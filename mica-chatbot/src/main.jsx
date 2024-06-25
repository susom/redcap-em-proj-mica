import React from 'react'
import ReactDOM from 'react-dom/client'
import App from './App.jsx'
import './index.css'
import {ChatContextProvider} from "./contexts/Chat.jsx";
import '@mantine/core/styles.css';
import { MantineProvider } from '@mantine/core';

ReactDOM.createRoot(document.getElementById('chatbot_ui_container')).render(
  <React.StrictMode>
      <ChatContextProvider>
          <MantineProvider>
              <App />
          </MantineProvider>
      </ChatContextProvider>
  </React.StrictMode>,
)

window.REDCap_Chatbot = App;
