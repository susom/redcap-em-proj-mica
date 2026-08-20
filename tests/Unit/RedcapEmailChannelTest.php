<?php

namespace Stanford\MICA\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stanford\MICA\RedcapEmailChannel as C;

/**
 * How a notification body survives the trip into an actual mailbox.
 *
 * Every assertion here is a rendering defect that was found by reading a delivered message rather
 * than by reasoning about the code, which is why they are pinned: REDCap builds the text/plain
 * alternative itself with `Message::formatPlainTextBody()` - `strip_tags(br2nl($body))` - and does
 * **not** decode HTML entities on the way. Nothing about that is visible from this side.
 *
 * `plainTextAs REDCapWould()` reproduces that transform so a test can assert on the part a plain-text
 * reader actually sees.
 */
#[CoversClass(C::class)]
final class RedcapEmailChannelTest extends TestCase
{
    /** The one URL that legitimately appears in a body, with the query string that broke things. */
    private const URL = 'http://redcap.local/redcap_v17.2.3/ExternalModules/?prefix=proj_mica&page=x&pid=257';

    /** REDCap's own derivation, transcribed from Message::formatPlainTextBody(). */
    private function plainTextAsRedcapWould(string $html): string
    {
        // The anchor rewrite: <a href="X">Y</a> becomes "Y (X)".
        $text = (string) preg_replace(
            '/<a\s[^>]*href=("??)([^" >]*?)\1[^>]*>(.*)<\/a>/siU',
            '$3 ($2)',
            $html
        );

        // br2nl, then strip what is left. Note: no entity decoding, which is the whole point.
        $text = (string) preg_replace('~<br\s*/?>~i', "\n", $text);

        return trim(strip_tags($text));
    }

    // ------------------------------------------------------------------ entities

    public function testTheDashboardUrlSurvivesIntoThePlainTextPart(): void
    {
        // htmlspecialchars() turned `&page=` into `&amp;page=`, which strip_tags carries through
        // untouched - so a reader on a plain-text client copied a broken link. The link is the only
        // actionable thing in a minimum-necessary body.
        $url = self::URL;

        $text = $this->plainTextAsRedcapWould(C::bodyToHtml("See the dashboard:\n$url"));

        $this->assertStringContainsString($url, $text);
        $this->assertStringNotContainsString('&amp;', $text);
    }

    public function testAnApostropheDoesNotArriveAsANumericEntity(): void
    {
        $html = C::bodyToHtml("the reviewer's rationale");

        $this->assertStringNotContainsString('&#039;', $html);
        $this->assertStringContainsString("reviewer's rationale", $this->plainTextAsRedcapWould($html));
    }

    #[DataProvider('bodiesThatMustNotProduceEntities')]
    public function testNoBodyLeaksAnEntityIntoThePlainTextPart(string $body): void
    {
        $text = $this->plainTextAsRedcapWould(C::bodyToHtml($body));

        foreach (['&amp;', '&#039;', '&quot;', '&lt;', '&gt;'] as $entity) {
            $this->assertStringNotContainsString($entity, $text, "\"$entity\" reached a text reader.");
        }
    }

    /** @return array<string,array{string}> */
    public static function bodiesThatMustNotProduceEntities(): array
    {
        return [
            'a query string'  => ["See:\nhttp://h/x?a=1&b=2&pid=257"],
            'an apostrophe'   => ["the reviewer's note"],
            'a quoted phrase' => ['status is "confirmed"'],
            'an ampersand'    => ['Smith & Jones'],
        ];
    }

    // ------------------------------------------------------------------ tags

    public function testAngleBracketsAreEscapedSoNoTagCanBeInjected(): void
    {
        // The only escaping that actually matters: bodies are module-authored, but a record id is not,
        // and nothing that could begin a tag may reach a mail client's HTML parser.
        $html = C::bodyToHtml('Record: <script>alert(1)</script>');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testAUrlSmuggledInsideAnotherValueDoesNotBecomeClickable(): void
    {
        // The injected tag is escaped either way. The danger was the *linkifier* then finding the URL
        // in the escaped text and anchoring it - a live link to somewhere else, inside a genuine MICA
        // safety notification, is worth more to an attacker than the tag they could not inject.
        $html = C::bodyToHtml('Record: <a href="http://evil.example">click</a>');

        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringContainsString('&lt;a href="http://evil.example"&gt;', $html);
    }

    public function testAUrlMidSentenceIsNotLinked(): void
    {
        // Only a URL alone on its own line is linked, which is how every real body is written. Record
        // ids cannot contain a newline, so nothing user-supplied can reach a line of its own.
        $this->assertStringNotContainsString('<a ', C::bodyToHtml('Record: http://evil.example is odd'));
    }

    // ------------------------------------------------------------------ line breaks

    public function testLinesAreSingleSpacedInThePlainTextPart(): void
    {
        // nl2br() emits "<br>\n" and REDCap's br2nl turns that <br> into a second newline, so every
        // line of the text alternative came out double-spaced.
        $text = $this->plainTextAsRedcapWould(C::bodyToHtml("Record: 1\nUrgency: critical\nBy: ra"));

        $this->assertSame("Record: 1\nUrgency: critical\nBy: ra", $text);
    }

    public function testABlankLineStaysExactlyOneBlankLine(): void
    {
        $text = $this->plainTextAsRedcapWould(C::bodyToHtml("Heading\n\nRecord: 1"));

        $this->assertSame("Heading\n\nRecord: 1", $text);
    }

    public function testNoRawNewlineIsLeftInTheHtml(): void
    {
        // What makes the above true. A newline here is harmless to render but becomes a second break
        // once br2nl has run.
        $this->assertStringNotContainsString("\n", C::bodyToHtml("a\nb\r\nc\rd"));
    }

    // ------------------------------------------------------------------ links

    public function testAUrlBecomesAClickableAnchorPointingAtItself(): void
    {
        $url = 'https://redcap.example.org/review?pid=257';

        $this->assertStringContainsString(
            '<a href="' . $url . '">' . $url . '</a>',
            C::bodyToHtml("Open the dashboard:\n$url")
        );
    }

    public function testTheAnchorHrefAndTextAreTheSameUrl(): void
    {
        // Visible text that hides the destination is phishing-shaped. A reviewer should be able to see
        // that a clinical notice is sending them to their own REDCap host before they click.
        $html = C::bodyToHtml("Open:\n" . self::URL);

        $this->assertStringContainsString('<a href="' . self::URL . '">' . self::URL . '</a>', $html);
    }

    public function testABodyWithNoUrlIsLeftAlone(): void
    {
        $this->assertStringNotContainsString('<a ', C::bodyToHtml('Nothing actionable here.'));
    }

    // ------------------------------------------------------------------ channels

    public function testItRefusesChannelsThisDeploymentCannotHonour(): void
    {
        // Claiming a pager it cannot reach would put "sent" in the trail for a notice nobody got.
        $channel = new C();

        $this->assertTrue($channel->supports('secure_email'));
        $this->assertTrue($channel->supports('dashboard'));
        $this->assertFalse($channel->supports('pager_or_on_call_system'));
        $this->assertFalse($channel->supports('secure_messaging'));
    }
}
