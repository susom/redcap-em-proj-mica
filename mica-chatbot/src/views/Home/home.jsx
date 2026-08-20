import { Container } from 'react-bootstrap';
import { Messages } from "../../components/messages/messages";
import Header from "../../components/header/header.jsx";
import Footer from "../../components/footer/footer.jsx";
import LaunchBanner from "../../components/notice/launchBanner.jsx";


export function Home(){
    /**
     * Read straight off the bootstrap rather than through ChatContext: it is a fact about the
     * project, fixed for the life of the page, and putting it in chat state would imply it can change
     * mid-session. Null on a production project and on a fully configured one - see
     * LaunchReadiness::developmentBanner().
     *
     * Rendered HERE and not in App.jsx, because App's component is dead: main.jsx renders AppRouter
     * directly, and App.jsx survives only to pull its stylesheets into the bundle in the right order
     * (see the comment there). The first version of this banner went into App.jsx, which shipped the
     * CSS and none of the markup - the E2E caught it, nothing else could have.
     */
    const launchBanner = typeof window !== "undefined" ? window.mica_bootstrap?.launch_banner : null;

    return (
        <>
            <Header />
            {/* Between the header and the conversation, outside `.content`, so it is unmistakably
                chrome and cannot be read as something MICA said. */}
            <LaunchBanner banner={launchBanner} />
                <div className="content">
                    <Container className={`body`}>
                        <Messages/>
                    </Container>
                </div>
            <Footer />
        </>
    );
}
