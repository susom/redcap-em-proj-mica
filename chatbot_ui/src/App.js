import React, { useState, useEffect } from 'react';
import Header from './components/header/header';
import Footer from './components/footer/footer';
import Splash from './views/Splash';
import Home from './views/Home';
import History from './views/History';
import './App.css';
import './assets/styles/global.css';

function App() {
    const [currentView, setCurrentView] = useState('splash');

    const changeView = (viewName) => {
        setCurrentView(viewName);
    };

    let ViewComponent;
    switch (currentView) {
        case 'home':
            ViewComponent = <Home changeView={changeView} />;
            break;
        case 'history':
            ViewComponent = <History changeView={changeView} />;
            break;
        case 'splash':
        default:
            ViewComponent = <Splash changeView={changeView} />;
            break;
    }

    return (
        <div className={`full-screen-container ${currentView}`}>
            {currentView !== 'splash' && (
                <>
                    <Header changeView={changeView} />
                    <div className="content">
                        {ViewComponent}
                    </div>
                    <Footer changeView={changeView} />
                </>
            )}
            {currentView === 'splash' && ViewComponent}
        </div>
    );
}

export default App;
