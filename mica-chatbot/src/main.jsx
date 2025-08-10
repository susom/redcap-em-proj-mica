import React from 'react'
import ReactDOM from 'react-dom/client'
import App from './App.jsx'

import './index.css'
import {ChatContextProvider} from "./contexts/Chat.jsx";
import '@mantine/core/styles.css';
import '@mantine/carousel/styles.css';
import { MantineProvider } from '@mantine/core';
import {AppRouter} from "./components/appRouter/appRouter.jsx";
// Strict mode will double invoke lifecycle methods for dev purposes :

ReactDOM.createRoot(document.getElementById('chatbot_ui_container')).render(
  <React.StrictMode>
      <ChatContextProvider>
          <MantineProvider>
              <AppRouter/>
          </MantineProvider>
      </ChatContextProvider>
  </React.StrictMode>,
)

