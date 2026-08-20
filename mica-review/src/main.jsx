import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { App } from './App.jsx'
import './styles.css'

const mount = document.getElementById('mica-review-root')

if (mount) {
  createRoot(mount).render(
    <StrictMode>
      <App />
    </StrictMode>,
  )
} else {
  // Said out loud rather than failing silently: a blank staff page is indistinguishable from "no
  // findings", which is the one ambiguity this whole dashboard exists to remove.
  console.error('MICA review: no #mica-review-root element on the page; the dashboard did not mount.')
}
