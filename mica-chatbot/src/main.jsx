import React from 'react'
import ReactDOM from 'react-dom/client'
// App's component is dead (AppRouter is rendered directly below), but this import is
// load-bearing: it is what pulls App.css and assets/styles/global.css into the bundle,
// and it does so *after* the component stylesheets that App.jsx imports first. That
// order decides which `.messages { overflow }` wins - App.css's `scroll` over
// messages.css's `hidden` - so replacing this with direct CSS imports here silently
// stops the transcript from scrolling. Untangle it in a layout pass, with a render to
// prove it.
// eslint-disable-next-line no-unused-vars
import App from './App.jsx'

import './index.css'
import {ChatContextProvider} from "./contexts/Chat.jsx";
import '@mantine/core/styles.css';
import '@mantine/carousel/styles.css';
import { MantineProvider } from '@mantine/core';
import {AppRouter} from "./components/appRouter/appRouter.jsx";
// Strict mode will double invoke lifecycle methods for dev purposes :

// Selected by attribute, not by id: `#chatbot_ui_container` is not unique on a REDCap survey page.
// The REDCap Chatbot module emits an identically-id'd empty div earlier in the document, so
// getElementById() mounted this app inside Cappy's container, on top of Cappy's own React root.
// Only MICA's container carries data-bootstrap. Keep in step with MICA.php's inline bootstrap.
ReactDOM.createRoot(document.querySelector('#chatbot_ui_container[data-bootstrap]')).render(
  <React.StrictMode>
      <ChatContextProvider>
          <MantineProvider>
              <AppRouter/>
          </MantineProvider>
      </ChatContextProvider>
  </React.StrictMode>,
)

