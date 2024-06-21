import React from 'react'
import ReactDOM from 'react-dom/client'
import App from './App.jsx'
import './index.css'
import {ChatContextProvider} from "./contexts/Chat.jsx";

ReactDOM.createRoot(document.getElementById('chatbot_ui_container')).render(
  <React.StrictMode>
      <ChatContextProvider>
          <App />
      </ChatContextProvider>
  </React.StrictMode>,
)

window.REDCap_Chatbot = App;
