<?php
/** @var \Stanford\MICA\MICA $module */
use REDCap;

header('Content-Type: text/html; charset=utf-8');
session_start();

$actionUrl = $module->getUrl('pages/chatbot.php', true, true); // keeps pid & NOAUTH

// -------- GET prelude: reset to welcome on refresh --------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // If this GET is NOT the first one after a POST redirect,
    // clear the view so a refresh always goes to welcome.
    if (!isset($_SESSION['mica_view_once'])) {
        unset($_SESSION['mica_view'], $_SESSION['mica_error'], $_SESSION['mica_name'], $_SESSION['mica_email']);
    }
}

// Pull view + error for this render
$view  = $_SESSION['mica_view']  ?? 'welcome';
$error = $_SESSION['mica_error'] ?? '';
unset($_SESSION['mica_error']); // show error once

// Consume the one-shot flag so the *next* GET (a refresh) resets to welcome
if (isset($_SESSION['mica_view_once'])) {
    unset($_SESSION['mica_view_once']);
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ---------------- POST handlers ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $step = $_POST['step'] ?? '';

    if ($step === 'login') {
        $_SESSION['mica_view']      = 'login';
        $_SESSION['mica_view_once'] = 1;
        session_write_close();
        header("Location: $actionUrl");
        exit;
    }

    if ($step === 'login_submit') {
        $name  = trim($_POST['first'] ?? '');
        $email = trim($_POST['email'] ?? '');
        try {
            $module->loginUser(['name'=>$name, 'email'=>$email]); // sends OTP
            $_SESSION['mica_name']      = $name;
            $_SESSION['mica_email']     = $email;
            $_SESSION['mica_view']      = 'otp';
            $_SESSION['mica_view_once'] = 1;
        } catch (Exception $e) {
            $_SESSION['mica_error']     = $e->getMessage();
            $_SESSION['mica_view']      = 'login';
            $_SESSION['mica_view_once'] = 1;
        }
        session_write_close();
        header("Location: $actionUrl");
        exit;
    }

    if ($step === 'otp_submit') {
        $code = trim($_POST['code'] ?? '');
        try {
            $verified   = $module->verifyEmail(['code'=>$code]);
            $recordId   = $verified['user']['participant_id'];
            $eventId    = REDCap::getEventIdFromUniqueEvent('baseline_arm_1');
            $surveyLink = REDCap::getSurveyLink($recordId, 'ui_hosting_instrument', $eventId);
            session_write_close();
            header("Location: $surveyLink");
            exit;
        } catch (Exception $e) {
            $_SESSION['mica_error']     = $e->getMessage();
            $_SESSION['mica_view']      = 'otp';
            $_SESSION['mica_view_once'] = 1;
            session_write_close();
            header("Location: $actionUrl");
            exit;
        }
    }

    if ($step === 'reset') {
        session_unset();
        session_destroy();
        header("Location: $actionUrl");
        exit;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>MICA – Access</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  html,body { margin:0; height:100%; background:#E6E7ED; font-family: system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; }
  .login-container { min-height:100%; display:flex; align-items:center; justify-content:center; padding:24px; }
  .login-card {transform: translateY(-40%);width:92%; max-width:760px; background:#fff; border-radius:12px; padding:24px; box-shadow:0 10px 30px rgba(0,0,0,.10); }
  .title { text-align:center; font-weight:700; font-size:136%; margin:4px 0 24px; }
  .grid { display:flex; gap:24px; align-items:stretch; }
  .grid > .col-text { flex:2; }
  .grid > .col-img  { flex:1; display:flex; align-items:center; justify-content:center; }
  .splash{
        width: 256px; /* Adjust size as needed */
        height:256px;;
        background:#E6E7ED url('data:image/webp;base64,UklGRsoXAABXRUJQVlA4IL4XAAAQcgCdASoAAQABPpFAmkmlo6IhJ3OMWLASCU3bygBq08TwX64+gSTey/7TjR0k91mcP/n+tDzCOfN5jP279Xj0t/3r1Dv+P1OXoAdL9/av+tY1P7HmLZlViz8jZ/e0XgC/jn6z33sAX169FGbpkAcKfQA/SvrIf6nlj+vPYS6Vn7uHpxpgmNdckz27zdGw44Crf93U3vW//rP8wQy1oUpTl2FWCOb/CrdEPO2Z5dgC6mmB7BVuSb6bq8fGWNUEL9///IPIH3NX+V1vB2k+aAWrWHyeiix9p4NSfBW6bCa3+Eqbdiy4x5t89i2MAzX1MDodK+AfxZNeLNUgaNQa9DZqdgrEfUVwS+sz0a5awxGCNf8wOGBPNfmmzMBFOsmFslnxfbIl4YlP+XlP2SIj+tyClOZMUnII8SV0Qp8PemRX6nyVgrfdirXyxlf2mTdp/rZbLwkqZEx1i8KVycs5yMpxQdHvdzupXHs1OhkI42cmPfebA/KR9Zw1uLMJ/vAJBHdQQA3BLyUW9UFxz4UFNSaGWDe2vLc9MqZt2Jz3PXYjT91fjPLaB36S22/1hp1cCBx6E9suLU62EQzG2nonZNtjjb9qvtvk5r6CwdVLBJmpAGWMcgvT05FLjKvqTM7IDg4R8TsPjTNtMGXpp3EIPdHkVdXsPxzxxmUUoav/i69KHaNNJ8tZcmFsB8ZACo+HXTGGTdD4va6CWYhuFJX65M7HCfpzTbjaugpVftov4kPeyweUvyYmgeHIxHQ7ItuwT10XXM3QZ2KklYkvDSMJdxhzV9fyDpvIrbDQM/gxJWyhlSGxVoHWzrSIIf3fWogyftxfAeRPcCqM6vjQiPvFaWJI7MqXQPOik0H6OuQMFZiQAB6FfX9zs9/hSfE2WMpt7xuD5Jnla9TMAKRR7smWeEyBeo+4fYnBl2oRAnKgWyOxXjUqu3E4r42AkILM2jVWcfrAjAHk4zFiJexC6669NtAFZzco9P4OlJgBcO2Rqak7ErDqLnC67st7etbMkz2jBuMdJZbHCxhjlMCAG2+ow7vS/zy5ieu3m7ydsGW2mxbWN0DC0eX2pk9L6GWl0+Jh7wdqWymPGXBK+GawKKKHvYYmm1KCXxSTx9bZZsOL4mWIZjsfmmNgteLwb3/ZeM4l7cg1VO4wjBiBbUBZussN8JiwYJQSGN4Cazx9lXPbtgGZLK7tth56Dl25lzlhk51HX/fouoeeIIs8sAAA/vmeVhUDt+3GzIRV5dko3KjJK1gCAwpeSfvj3gJ9lTc08uoa6IBF4ygtwB281cjQbgzdBSTFQ3OaBngBJoAryRbnu6jhCiYfC7bQzjc78JAgIc3nqipDrgZ6QSkIJu1Igw/FhPUmb1Zz4ATAy2QjtZSs/IloXUyATfQn8T4zNnWLMIcYhDIz2B66nAVSb6NDbyzs6c0AXT+qJ2jtDbi7lXO8TK/y8uVovpSrEbtRy/wFf/w2vP5nKcxwWpagKDyDl2hasXVSQIJTu+es9dwf9aHPS4ipqCZCXA659G8CBcO76ARz8CaxR8nhYqpwWoJUZh6CUJnEoZzObnRva5PYdsTm93REZjfQFDMFERVTjjGWTNVOlCsw0zUU0gcEHG/j5eayU29/cdZofNrE52urbpVbxzdmSVIQm4DsTNpAut6z0rGf5+d2+awS8OttUyO/8T5YhAK0yhDB2CNpGQRuAhfpstHBPrAEY9q/yBHaqDsBhIyh1TBPlHKMZdNyguV3xoDxqJkaCpA+duXt8nDQHzFYzpKjqn6iZh2PiJFGZGg/wLYzropsIEG6ogUouX8m8o+RqF6cik6cPXFpzRc7a6h8WXGSn8JQFc1y+5ieKV+Js/nPnAYP3PGeeclMXbBKzuxafPGaXwZ/bUNM3CJPY+Ny8FlmB7NoQeerRFqfzru+PTfQTr73zeR/WALwXG++xf8yLqNoZGo/n5hPea6nhA2G892Ifb+bX1qqwvWShDQq71OIQuReyY15asQizvLuCbfB0qA2bWc5LuuEAooihrtPwfBk15GJDWZRJGnJd74g6PwENEOqPjanVCPOuPGHn475c+VgjjsXg00LTtGkF/DebGeoCybVV5NfA6khKKpOaXx3avaZoRjFvf+6IKbmWPBnloEYB3GJUTvSgEPMYIiwiTpqLPAwhAYNiaFW5az5pix9PNvtotW6+kzkzIQiMO05VBlbbdCXrXEeG8dbxfr1xS/TQ9BuwnSS+S8ak+DQHs+RsOUj0ln4TBmPdk4iI4Nq9gbAUaBpf4Nc2hfGdNzt+iz+oTyJSZOMJI9iPPth4vN0Il0x3XJVJo8pxaTNqgJlFIph4DzOdm7IsL5wUv+XqNRergPb/7oWMgKJxPxQjbr8WETpCCtDm69INWTH/dCJhmwbnP4t1h0cixi5quZAntjTB6DxqXvYvpNfN97EfOdzXDWhkx7O7HGQl1+GstEmIJ5awQwVo031A0uQ0PHRTRoWNeVzahfXw6O5bhpzMudJ1bw8eKaqVNDFOy3Kv2veP40gw1qMPOp94BYiM5ZXZsu9YrYk8MGMdcTButVhop/JBRcAV//e2xGGXEryfnJu3uDZjULlHWbyepVWB8xOhMMV8VuGG07y9RXF+JQrijBEN5OmH8CTW5f4tQiMGkrGyKj54k3CCbi/QOTz5hvbvHzNSjyRn5HxEbd3K2SbkBD3l5hoFZFO7xkqsPS81IXTz/oeMrg8CU1xp4k+V0tX9zvUGPgl58njjMP/zUkRddG1zXQEyM4Syn39Tdd/B3JI2fo0Afyz/gXECDETpZp/zOmm2s6EoKLDpOevvzKC8hskiVQYuZ0m400sKruqWeMK+AQ8TflwTa9sHX+MU/H7uuUhuioiHgLPSjRZSpXIOdyHPTroPgy8Z3rBn2Tqv6iaN+WukkWjls5OloHKw4ncM8cmmEsZ0D8xy+SWBziFaB71p/kvvinhE3pMUb7ANI0I6qG1EmfFvWZQlV+dHNj4j2pJBmBUmty9iZ2mBQRNiQffDYtLN4TZ9Y+BmShrFT2eMsID1dADbrx8psZgNn26zx13FqAHDRZGJ049iDFJ8Fg5PsRHhdq5RZugXMCgPy0RUJmfZVybJ/V/FHoRYZ62Sph7sbZXFYwecoVgeLZ73pADmPGbnp0jJHKHrj+LDVuCRfSZVL+xtrED6SY/bIjVtPxHoaRYFZWFpzcBirpGaZO41EmIEhGgyS+aPRGto9bL2buymQ9L9f67jjzJ5nTM1/l+XlxHAy+4K8oAA/Ev21l716+4Y/V/+JBI3TdoykhH45e1fXfTHVNUCTdXSwfvHlxeYgzDYtn5VnD7s9oQdbB/2rKx8DU2OgfujpQyM2j61vTV5lJx+YaYIPFQwOJQNjriRVgLwzNJSykhEo69wndLcCTryqaNwHoY9LhHbbsawlCwo02FWHQ4EhloabNhL8d+mtkhvfcSe74kGUa4E2oiyQGX60/i/3KG5vWB99vIEcMC19lH98HkYdpomTIpPW/7tsbC+vopuEJfpkh2u3V/iAiHE+5giCNrThFTjG/tifHAb8hT1vLko9eFUWkXIaA7WUGdn/sTDQ0GBAQBin64RGl3+6kJQu7E0xE6xcb/AXUQH+Zon1op7GjerpVMHNzk/LIHFr7qk2XlTwEGbaZ598fihO+REQjzgaOKT32v8ocLdljM/f2U/kRjgVAI4Wl16eVDASA0tYfgqW8XG0ytMzxlymkG03nQ8dEfB8SGxDV3GIIH2hduoMPwQ0u5521coyCZdH8SSGgCkbkhjeRbehQ7szzibRkC5nAS2lOXRJrmaz/l45F0dB7dqXOM83/yx6tJHl1paG6+FZLSxfEWgI9tPBycYKmqGk5sH8oyAd+nyghXto29zz964sUQ2hcGNeJnaJfN1dMJFQb+8JlGWBY/i5P5toJGGBlpEOB6opix0akXgwBrsHQerR+Q7F1CyQYc/xHwc+hlNbjVlw8FZbn9fSjhpHNto+n0nzvr9v6/UhzCCjOk6pfUzhkP8yY81MenjM1lEr++d8EQuZmgbeUkm25HxqlbAFsLTdrqtFrPYyEREvKYZbL7weQenG/6fkfZW6KNsu8CDRHLEjJPKJcCLoA7ybmiT7w0mf1zWK+iAkoSK9eQEF5hTn71Wi+0/aEeeYpFM/LfmLWi++3evlif4n2sNCkhLxtpRaLyFUQsWJmje4BHH7Ff8+bxeY8gLKRSzLHMhfQxwwDOHQALPN7lMEIIHHmw2+n2u2NHlfyFWWiQSm3fxuT3K6Mggi/QeFzivDZEWqcXOOdhFzSflxsZ9BzEpHARCmf36+CnJmywFc5ydat5h1L4ETzBfAvVRKMQSOvmAgU4KuTwq6IX4dZEofoRnGtw0eINmo8YO734FYWGbHOQdh2oItB/xHLv6HrV8VSYt/DlkVkA2WGyEbIKdrw19yaV87LMcbHjSLzT7ogO9cemglw5wIbHu2nmiZp6rmiQm6rSFDw+Izqym9YwyV9q0B0aoKsehzTs7eSL7CGTsNWtr69Zy8rJ+10U/SkshEfvCMVwIbr/Gm/Yziyn7gYERB/FMJWj7Prpi8C3+SHyql9BQ0hhFYq55GNKa3dqQjCqljaqIIWmsCUcP5/63WPQjeis6cDfJ3mxrWZP3zOcf6nVt5z2TSUAMwUdKQkYpy5yL5JO2WJIrKfmI6YoRMMzku3iQD4Af7b1GxOrefTg4OW4GNq9K+nfy2tK3RQz53nR1IM7HafaBFjYKWnJ1c0fCimBhyJGwoJwKPgzI2kAHlbL/djkFp8EeqQQAakjEb3gCiGZFi/0SepUaqSKUkNTNqyOeA/nc22MAwIvxb27hAfYKTWiepU3AzoGyHrZGHlFHOIDVlt7Ba8NaVcRq4bwWOl1ZTKjuIkjQNHkSrti5jkRGI5VogI1YcY1BBX4a93LQH+oKBNTzrxNZSN1XtM4kifOZUbCOXl/lmI0LcG+9sDpktCUrOGS9woV50VNb7JMql1WKGN37Ix866YBiu+F6Mo/2yR9MQPeFzHedP/AjXMvF7Acz9v8eJoBRWWKXK0QzvOdGbGPIaMZ4piUlVSjmYG7TCePtNA4Qj4g6FKcl++I6zufnshQbgY4Dcybw0Omb8foffWRkll+vypCc7fAKc6uOaoDb5ufZlTxQIrbObjnWCvqRHciPHlGcxFBzARb5Aob1r+/ISywwTXcA78HeycCfGIrKgafR2Grw6klHprJdNJTPYDuCQQhfp3EVnXf9hz3zpM5cOw9fRJs7PDdRQRlWa28ktPjzlMQ19oejFMsdqpG0O9zSdlourYup1o/F18E+Ibj/rWoV7R+YeazajhQDfAFU5ZPG5kkMzm5HTBLnI99BskUSgUZSev2Y3kAisMqJDqPekow+gShBNZLA0jextireh7uypTq6n5oJXwVi1CAqg+1im4MHJK2cB+zTUv1+MThvzsEG5KCVx5/88FpamX3yDJdRVFRQIJQQ1CywRwIiszQ3ODszFKIp7Q0obWKBxNd8cyBcgWslV3g9n4o53cIk2Eq4nLmXZA+KiVfcSc87J8wEffsmcc4AsmyXc8DqRWV1tbv+D+M3M9oe0TMe4/CnFBe/AWDvqF2OcCdSAuwLmauNr2kJKeeht54ghffk1Q+IpT8bLAsz8klRppxWyCuDXYAQnVkptf/BWg/SA/4k+/99D8n6AxbsO3lttDuspK08ox8uk+5G9Y+4blCZDIpT0x2bTEbZcjsSUZdERsA1Ud4gkw8vZch7TkL74is8lG01M8fSeHiiPRdVkGTB9bMhrB2q5h86TBY1jnn1rxA3G0yr/2gjLluE890pJnwtGVbHOAjZjSJfLktbxLgjg6H4zLlDIvzqTYFbICvvD7Rs/5eKhwWbgpNYaR1uremJPq3Q63ZQyGOxwiGqatf0RJG9HzDrQuszevx/Ip3N2kQ7ZKPWJgr8R+eUH5gL01Yn/fZe4suvt+bpNQkafpLgM9qEGXMxMb9waHjLmzuOFWM+8Uybyyb+PYMibf03SXqPASFILp5+7AHY5dxMBCrBzrLy8DMQgy8TFXw9WFQjzQN1FbJOG6bWrj+HJ5EpIgKEaU5DRfw0hfuujWPZgYuBuPX7vZ0DmREBC/tHWuGyGGvdh9sDv9LOWcn8i6BV/+TLYgp1kydshXBvcgOHRW/z7++p9Bry6guAKhQiuf3xSN01iIOBq3Ta1dqifqJQI1QWysaK9xBd3Df9PAdHFu+SzSrz/Irr5N+FWah1dETjXxToKDrEAe7XUo3Va4EnmSYE56DlY06bgrsAYD09JmUHIOK7vNPwCS81KN51zFVs8+uzhHjpwu9JbNg37Xw/eV6hmv0aZT4vIONT3Sce+iWF6Y+grwei44hWK84g7/XSJWKqGqFtZKt6PSxXH1ffuBmA287S/kkWVwZqTAMXHoO5B89jYA9wuuCtoT4n6RWncJwdN/mHjHesqhCbXfvxhEX8mldOnOQQrF3KDzZEJYoHOA9txZ2i+l9AUTfUHGlHYYxzj8Z09u2D3ebKxKtTpwyTQOPVhaoiyn7ySXhC3WSoM5Lp9huS7CqqAe8iVpiVOAhaylNgkWGijfimNoO5oVeH3S6gzRVuGcN/FvDbn1yoekpELZxp80bMcA97Euc4SFBYS62QDNKEJor0aucds2BplAUAHcAA/oofrzqjf2K7106SUaRFYaknGcGBa2dwFEu0g/fZJ3KCbjDtfE4qlmC5YSvWevnxyj74JC2uDp3vEOdChLbmzY7LUoj+hzQLqtVwejGKoXm5bYbxTGFF0MEd9x37NHSmr7CcASEFP+0nYul4Ir4Pr+BvKCKGr+7gBGwRLf/vrDFCZThgT2fXoEkeFrACLzsHAI9JJhAY1qrI2Va1GPDL9j4OWQAWLH8oqRSAYJT01mCUaItRFzSEHPtPqAJqoE7jn9s36EkXiy/TBFSXiqwJZBOf2kQqKk3hJ2EfCH4x6yFjU63bHGkc6mOp1xl1J5+nKdbhUo78P+ejJNEgzjR4KdQxzoV6Tk9JWU0YKJ4iwl8iRcHEt3cCloQaufXiZCVXsSX7iZFgnIXizKCiXYaN3k9oLRcPKRSkYWHvX/Zycp5Awt8XrAtfbI4mhMEMUgBv+/OeTiAx9WHZWnZb2kRtl7uThIoZzCuONLvcFr4Sa8kvDTWRyeIksA/wmr0AorqFHFoYCe/HoIpPoZRve5bsy/urVsnkrme0WEhhSMQNBbp5dDdN0ET1beFJ22NTYFGFlvbUFyXFFg/xsj6CxidEMN5mV7F7MusflATnYNl39xVoON7pshZuTH3nzJV0S7fQ6gLmpvALUqU65MS/3UC8NmnN0GYfgnf5pv/G0lpS3bpCbKR2dmTW8748/auw1rvkChwYC2yODIu5h+lv/DMkbIELY/dljscvYv5sgQyI4/8Nx+TWbyvKB8ZdLtXyc4jbKtb13om5LuqzpRxZSNQnLUkbU47Q6V9MzwbV/43X7LJC3KmT449wHGj6WWr1LXnaG3H3jjslw3t5zg+CHFFCr9iClhargefJP1lQ110UJcD3kALUZ+277QZJZe9NDkddkZZ0bdvHAS/mk/HGvUGt7rA8fK/fcc1lqbMMx+qcPIHrdRI2DA7GOvtDNYFl+Ac0d7rzo6m7U5MspXomkefW9ADyfIM88YVjUJpCV7R5AAAB8Qa5ClPneAcrw//Z1mgentfviIsPBGZcAq0OChtl7c3gIVLbBZ98pQuppYkCXDZU+WZRqtHzCrbJ5sjU7tpq0veVKNwL5eqm21zcGYnpJ9XKNf+jXsIb86GHE+enP+3lie4r4O+Kb4IjgqQfaknet/KX38wncT7TImDYlUB7v9p8rqJkYtERSl1W3kB5GU/+/pwD+b1MmnOaCTOqe5iZMAMSirsPyVYrZVhhw62KffPKh9ng+M9+xS/lgjH3Of3fZMVKnICqEFACDy+xqiSSU2vilIGVrcbzhrWM7Hr0NFQbwo1AzBfLwLARTaFy10a88BBQ+jBT/iepR23QZhA4ORSXHgz7OWuRi0/fQ6Wv6CShzdszgovlMWm8GJIgrlwSH0w8gPTCcDr33itusKAmPJJZpEXAA11wpMB+rpJxyghcYQ52mRaCYH4AYUAAAA=') center center no-repeat;
        background-size: cover;
        border-radius:15px;
        cursor: pointer;
        z-index: 10000; /* Ensure it's above other elements */
    }
 .otp.fieldset,
  form.fieldset{
    border: 1px solid #DEE2E6;
    border-radius: 4px;
    padding: 12px 24px;
    min-height: 240px;
  }
  .otp.fieldset .legend,
  form.fieldset .legend{
    color:#000;
    font-size:88%;
  }
  .legend { 
    font-weight:500; 
    color:#868E96;
    margin-bottom:14px;
  }
  label{
    display: block;
    font-size: 88%;
    margin-bottom: 3px;
    padding-left: 2px;
  }
  .input { width:100%; box-sizing:border-box; border:1px solid #d1d5db; border-radius:3px; padding:10px 12px; font-size:14px; }
  .row { margin-bottom:18px; }
  .btn { display:inline-block; border:0; min-width:120px; font-size:92%; border-radius:8px; padding:10px 30px; font-weight:500; background:#8C1515; color:#fff; cursor:pointer; }
  .btn[disabled]{ opacity:.6; cursor:not-allowed; }
  .center { display:flex; justify-content:center; }
  .error { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; padding:10px 12px; border-radius:10px; margin-bottom:10px; }
  .fineprint { text-align:center; color:#878F98; font-size:78%; margin-top:15px; }
  @media (max-width: 768px){ .grid { flex-direction:column; } .splash{ width:160px; height:160px; } }
  .btn-row{
    display:flex;
    gap:12px;
    justify-content:center;
    align-items:center;
    margin-top:16px;
    }
    .btn-row form{ margin:0; }
</style>
</head>
<body>
<div class="login-container">
  <div class="login-card">
    <div class="title">Welcome to MICA!</div>

    <div class="grid">
      <div class="col-text">
        <?php if ($view === 'welcome'): ?>
          <div class="fieldset">
            <div class="legend">Terms of Usage</div>
            <div class="row" style="color:#475467; font-size:14px; line-height:1.55">
              Before we get started, it's important to let you know that this is a chatbot session. The intent of the chatbot is not to diagnose, treat, mitigate, or prevent a disease or condition.
              While our chatbot is designed to provide helpful and supportive responses, please remember that there is no human monitoring this data in real-time. If you are experiencing any acute issues or need immediate assistance, we encourage you to reach out to your nearest health center or emergency services. Thank you for your time and contribution. Let's get started!
            </div>
            <form method="post" class="center" style="margin-top:14px">
                <input type="hidden" name="redcap_csrf_token" value="<?php echo $module->getCSRFToken(); ?>">
                <input type="hidden" name="step" value="login">
                <button class="btn" type="submit">Continue</button>
            </form>
          </div>

        <?php elseif ($view === 'login'): ?>
          <?php if ($error): ?><div class="error"><?=h($error)?></div><?php endif; ?>
          <form method="post" class="fieldset">
            <input type="hidden" name="redcap_csrf_token" value="<?php echo $module->getCSRFToken(); ?>">
            <div class="legend">Registration information</div>
            <div class="row">
                <label>First name</label>
                <input class="input" name="first" placeholder="Your name" required>
            </div>
            <div class="row">
                <label>Email</label>
                <input class="input" name="email" type="email" placeholder="Email" required>
            </div>
            <div class="row center">
                <input type="hidden" name="step" value="login_submit">
                <button class="btn" type="submit">Login</button>
            </div>
          </form>

        <?php elseif ($view === 'otp'): ?>
            <?php if ($error): ?><div class="error"><?=h($error)?></div><?php endif; ?>
            <div class="otp fieldset">
              <!-- The OTP form holds the input + hidden fields -->
              <form id="otpForm" method="post">
                  <input type="hidden" name="redcap_csrf_token" value="<?php echo $module->getCSRFToken(); ?>">
                  <input type="hidden" name="step" value="otp_submit">

                  <div class="legend">Please enter the 6-digit code sent via email</div>
                  <div class="row" style="margin-top:60px">
                  <label for="code">Code</label>
                  <input class="input" id="code" name="code"
                          placeholder="_ _ _ _ _ _"
                          autocomplete="one-time-code" required>
                  </div>
              </form>

              <!-- Buttons row (side-by-side) -->
              <div class="btn-row">
                  <!-- Separate form so it bypasses required validation -->
                  <form method="post">
                      <input type="hidden" name="redcap_csrf_token" value="<?php echo $module->getCSRFToken(); ?>">
                      <input type="hidden" name="step" value="login">
                      <button class="btn" type="submit" style="background:#e5e7eb;color:#111;border:none">
                          Back to login
                      </button>
                  </form>

                  <!-- Submits otpForm -->
                  <button class="btn" type="submit" form="otpForm">Validate</button>
              </div>
            </div>
            <?php endif; ?>

      </div>

      <div class="col-img">
        <div class="splash"></div>
      </div>
    </div>

    <div class="fineprint">© Stanford Medicine</div>
  </div>
</div>
</body>
</html>
