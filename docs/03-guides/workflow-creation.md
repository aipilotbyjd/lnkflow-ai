# The LinkFlow Studio: Content Creation Guide

Welcome to the Director's Chair. In LinkFlow, "making content" means building **Workflows**.

Think of a Workflow as a movie scene. You define the **Characters** (Nodes), write the **Script** (Connections/Data), and shout **"Action!"** (Triggers).

---

## 1. The Characters (Nodes)

Your cast is divided into 4 troupes. Here is every character available in your engine (`apps/engine/internal/worker/executor/registry.go`):

### 🎭 The Triggers (They start the scene)
*These nodes have no input—they create the first spark.*

| Character | Role | When to use |
|-----------|------|-------------|
| **Manual Trigger** (`trigger_manual`) | The Rehearsal. Starts when you click "Run" in the UI. | Testing, debugging, one-off tasks. |
| **Schedule Trigger** (`trigger_schedule`) | The Clock. Starts at specific times (Cron). | Daily reports, hourly checks (`* * * * *`). |
| **Webhook Trigger** (`trigger_webhook`) | The Signal. Starts when an external app hits a URL. | Receiving data from Stripe, GitHub, Typeform. |

### ⚡ The Actions (The Doers)
*These characters interact with the outside world.*

| Character | Role | Capabilities |
|-----------|------|--------------|
| **HTTP Request** (`action_http`) | The Messenger. | GET/POST to any API. The backbone of integrations. |
| **AI Agent** (`action_ai`) | The Brain. | Uses LLMs to generate text, summarize, or think. |
| **Email** (`action_email`) | The Courier. | Sends emails via SMTP. |
| **Slack** (`action_slack`) | The Broadcaster. | Posts messages to Slack channels. |
| **Discord** (`action_discord`) | The Gamer. | Posts messages to Discord webhooks. |
| **Twilio** (`action_twilio`) | The Caller. | Sends SMS messages. |
| **Database** (`action_database`) | The Archivist. | Runs SQL queries against PostgreSQL/MySQL. |
| **Storage** (`action_storage`) | The Vault. | Uploads/Downloads files (S3, MinIO). |
| **Script** (`action_script`) | The Hacker. | Runs Bash/Shell scripts on the worker. |
| **Code** (`action_code`) | The Developer. | Runs JavaScript/Python snippets (Sandbox). |

### 🧠 The Logic (The Script Supervisors)
*These characters control the flow of the story.*

| Character | Role | Behavior |
|-----------|------|----------|
| **Condition** (`condition`) | The Judge. | If `x > 10` go True path, else go False path. |
| **Loop** (`loop`) | The Repeater. | Iterates over a list (e.g., for every user -> send email). |
| **Delay** (`delay`) | The Pause. | Waits for X seconds/minutes before continuing. |
| **Logic Condition** (`logic_condition`) | The Complex Judge. | Advanced AND/OR logic (Alias of Condition). |

### 🛠 The Utilities (The Crew)
*These characters manage data and stage props.*

| Character | Role | Function |
|-----------|------|----------|
| **Transform** (`transform`) | The Editor. | Maps, Filters, Renames, or Deletes JSON fields. |
| **Output Log** (`output_log`) | The Diarist. | specialized node for debugging/logging execution data. |

---

## 2. The Script (Data Flow)

In LinkFlow, data flows like a script passed from actor to actor.

1.  **Input:** What a node receives from the previous node.
2.  **Output:** What a node produces.
3.  **Expressions:** You can reference data using JSON paths.
    *   Example: `{{ $node["HTTP Request"].json.body.id }}`

---

## 3. How to Make Your Content (Step-by-Step)

### Scene 1: " The Morning Briefing"
*Goal: Every morning at 9 AM, fetch news and email it to me.*

1.  **Cast the Trigger:**
    *   Drag **Schedule Trigger** to the canvas.
    *   Config: Cron `0 9 * * *`.

2.  **Cast the Action (Fetch):**
    *   Drag **HTTP Request**.
    *   Connect Trigger -> HTTP.
    *   Config: `GET https://api.news.com/latest`.

3.  **Cast the Editor (Transform):**
    *   Drag **AI Agent** (or Transform).
    *   Connect HTTP -> AI.
    *   Config: "Summarize these headlines: {{ $node.prev.output }}".

4.  **Cast the Courier (Email):**
    *   Drag **Email**.
    *   Connect AI -> Email.
    *   Config: To: `me@example.com`, Body: `{{ $node.prev.output }}`.

5.  **Action!**
    *   Click **Activate**. The show runs automatically.

---

## 4. Advanced Techniques

*   **Loops:** Use the **Loop** node to process arrays. Connect the "Loop" output to the processor, and loop back if needed (or LinkFlow handles the iteration internally).
*   **Webhooks:** Use `trigger_webhook` to let other apps (like Stripe) start your workflows.
*   **Logs:** Use **Output Log** anywhere in the chain to see exactly what the data looks like at that point.
