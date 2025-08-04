# MICA: Motivational Interviewing Conversational Agent

This is a REDCap External Module (EM) developed to support the **MICA Project** — an AI-powered Motivational Interviewing (MI) chatbot for engaging participants in structured conversations about substance use and behavior change.

---

## Overview

MICA is designed as a **longitudinal digital intervention**, embedded in REDCap, with the following goals:

- Support behavior change through MI-aligned chatbot conversations
- Operate securely within Stanford's infrastructure
- Provide flexible follow-up sessions with context-aware chat histories
- Automatically handle scheduling, session flow, logging, and post-survey logic

MICA interacts with Stanford’s **SecureChatAI** instance, ensuring all data remains compliant with PHI restrictions (site-to-site VPN, no external API calls).

---

##  Key Components

### This EM (MICA)

- Installs a standalone chatbot interface inside REDCap
- Handles:
  - Participant session detection and routing
  - Dynamic System Prompt configuration per session
  - Session transcript capture and saving to REDCap fields
  - Session catch-up and summarization logic
  - Chatbot context management (e.g., system/assistant/user roles)
  - Completion logic to forward users to post-surveys
- Extends functionality of SecureChatAI EM (required dependency)

### Chat Frontend

- Built in React; produces a static bundle injected into the EM view
- Resides in `/static/` and renders within REDCap
- Can optionally be embedded elsewhere

---

## Installation

1. Clone this repo into your REDCap `/modules/` directory:

    ```bash
   git clone https://github.com/YOUR_ORG/mica-em.git mica_vX.X.X
    ```
2. Enable the module only on the REDCap project used for MICA
3. Ensure SecureChatAI EM is installed and configured
---

## Required REDCap Project Setup

**Longitudinal enabled:**  
Must have events such as `baseline_arm_1`, `session_2_arm_1`, ..., `session_7_arm_1`

**Instruments:**

- `session_info` – stores raw chat logs and timestamps  
- `posttest` – post-session survey  
- `month3_fu` – optional final follow-up survey  

**Fields:**

- `raw_chat_logs`  
- `session_timestamp`  
- `session_info_complete`  
- `des_mica` – used for opt-in/out decisions  

---

## Session Flow

- On login, participant is routed to their eligible session  
- If prior sessions exist, a summary is injected into the system prompt  
- Chat session completes, logs saved to `session_info`  
- Participant is redirected to `posttest`, and if applicable, to `month3_fu`  

---

## Project Settings

- `chatbot_system_context_general` – default global system prompt  
- `chatbot_system_context_session_X` – optional session-specific overrides  
- `chatbot_end_session_url_override` – optional redirect override for post-survey  

---

## Security Notes

- All chat processing uses Stanford’s SecureChatAI instance  
- No third-party API calls (e.g., OpenAI, Claude, Gemini)  
- All data remains within Stanford’s secure REDCap and hosting environment  

---

## Dependencies

- SecureChatAI EM  
- REDCap v13+  
- PHP 7.4+  
- Node.js for React frontend rebuild  

---

## Dev Notes

To rebuild the React frontend:

```bash
cd frontend
npm install
npm run build
```