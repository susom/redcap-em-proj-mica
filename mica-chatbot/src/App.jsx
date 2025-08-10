// src/App.jsx
import React from "react";
import Header from "./components/header/header.jsx";
import { Footer } from "./components/footer/footer.jsx";
import { AppRouter } from "./components/appRouter/appRouter.jsx";
import "./App.css";
import "./assets/styles/global.css";

export default function App() {
  return (
    <div className="full-screen-container home">
      <Header />
      <div className="content">
        <AppRouter />
      </div>
      <Footer />
    </div>
  );
}
