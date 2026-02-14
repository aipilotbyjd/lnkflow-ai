# Your First Workflow

In this guide, we'll walk through creating a simple but useful workflow: **A Daily Weather Report to Slack**.

## Prerequisites

- LinkFlow instance running
- A Slack Workspace (and a Webhook URL)
- (Optional) OpenWeatherMap API Key (we'll mock this for now)

## Step 1: Create a New Workflow

1.  Go to **Workflows** in the dashboard.
2.  Click **Create Workflow**.
3.  Name it: "Daily Weather Report".
4.  Description: "Sends weather updates to #general every morning".

## Step 2: Add a Schedule Trigger

1.  Drag a **Schedule Trigger** onto the canvas.
2.  Set the CRON expression to `0 8 * * *` (Every day at 8:00 AM).
3.  Set Timezone to your local timezone (e.g., `America/New_York`).

## Step 3: Fetch Weather Data

1.  Drag an **HTTP Request** node.
2.  Connect the Trigger to this node.
3.  Configure:
    -   **Method**: `GET`
    -   **URL**: `https://api.open-meteo.com/v1/forecast?latitude=40.71&longitude=-74.01&current_weather=true`
    -   **Label**: "Get Weather"

## Step 4: Format the Message

1.  Drag a **Code Node** (or AI Node) to format the text.
2.  Connect "Get Weather" to this node.
3.  We'll extract the temperature and condition.

## Step 5: Send to Slack

1.  Drag an **HTTP Request** node.
2.  Connect the previous node to this one.
3.  Configure:
    -   **Method**: `POST`
    -   **URL**: `https://hooks.slack.com/services/YOUR/WEBHOOK/URL`
    -   **Body**:
        ```json
        {
          "text": "Good morning! Current temp is {{ get_weather.body.current_weather.temperature }}°C"
        }
        ```

## Step 6: Test & Activate

1.  Click **Save**.
2.  Click **Test** to run it immediately.
3.  Check your Slack channel!
4.  Toggle **Activate** to enable the schedule.
