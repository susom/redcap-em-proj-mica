// src/App.jsx
import Header from "./components/header/header.jsx";
import { Footer } from "./components/footer/footer.jsx";
import { AppRouter } from "./components/appRouter/appRouter.jsx";
import LaunchBanner from "./components/notice/launchBanner.jsx";
import "./App.css";
import "./assets/styles/global.css";

export default function App() {
  // Read straight off the bootstrap rather than through a context: it is a fact about the project,
  // fixed for the life of the page, and putting it in chat state would imply it can change mid-session.
  // Null on a production project and on a fully configured one - see LaunchReadiness::developmentBanner().
  const launchBanner = typeof window !== "undefined" ? window.mica_bootstrap?.launch_banner : null;

  return (
    <div className="full-screen-container home">
      <Header />
      {/* Between the header and the conversation, outside `.content`, so it is unmistakably chrome
          and cannot be read as something MICA said. `.content` is the scrolling flex column; the
          banner sits above it and stays put. */}
      <LaunchBanner banner={launchBanner} />
      <div className="content">
        <AppRouter />
      </div>
      <Footer />
    </div>
  );
}
