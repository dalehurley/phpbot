---
name: browser-automation
description: "Automate browser interactions using Playwright and Google Gemini Flash. Use this skill when the user asks to control a browser, automate web tasks, scrape websites, fill forms, click buttons, navigate pages, or perform any browser-based automation. Combines Playwright for browser control with Gemini Flash for intelligent decision-making."
---

# Browser Automation with Playwright and Gemini Flash

## Input Parameters

| Parameter | Required | Description | Example |
|-----------|----------|-------------|---------|
| `task` | Yes | Description of the browser automation task to perform | Navigate to google.com and search for "AI news" |
| `url` | No | Starting URL (optional, can be inferred from task) | https://google.com |
| `headless` | No | Run browser in headless mode (default: false for visibility) | false |
| `timeout` | No | Maximum time in seconds for the task (default: 60) | 120 |

## Procedure

1. **Install Playwright if not available**
   - Check if Playwright is installed: `which playwright` or `npm list -g playwright`
   - If not installed, install it: `npm install -g playwright && playwright install chromium`

2. **Parse the task using Gemini Flash**
   - Use `gemini_code_execution` to analyze the task and break it down into steps
   - Prompt: "Analyze this browser automation task and break it into Playwright steps: {{TASK}}"
   - Get structured action plan from Gemini

3. **Generate Playwright script**
   - Create a Node.js script that uses Playwright to execute the task
   - Include intelligent decision-making by calling Gemini Flash for complex interactions
   - Script should:
     - Launch browser (headless or headed based on parameter)
     - Navigate to URL if provided
     - Execute automation steps
     - Take screenshots at key points
     - Handle errors gracefully
     - Return results

4. **Execute the Playwright script**
   - Run the generated script: `node {{SCRIPT_PATH}}`
   - Monitor output and capture any errors
   - Save screenshots to a temporary directory

5. **Use Gemini Flash for adaptive decision-making**
   - When the script encounters dynamic content or needs to make decisions:
     - Take a screenshot
     - Send screenshot + context to Gemini Flash via `gemini_code_execution`
     - Get next action recommendation
     - Execute recommended action

6. **Report results**
   - Summarize what was accomplished
   - Show any screenshots captured
   - Report any data extracted or forms filled
   - Indicate success or failure with details

## Output

Returns the results of the browser automation task including:
- Task completion status
- Screenshots of key moments
- Any data extracted from the web page
- Error messages if the task failed

## Reference Commands

```bash
# Install Playwright
npm install -g playwright
playwright install chromium

# Check Playwright installation
which playwright
npm list -g playwright

# Run Playwright script
node browser_automation_script.js
```

## Example Playwright Script Template

```javascript
const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch({ headless: {{HEADLESS}} });
  const context = await browser.newContext();
  const page = await context.newPage();
  
  try {
    // Navigate to URL
    await page.goto('{{URL}}', { waitUntil: 'networkidle' });
    
    // Take initial screenshot
    await page.screenshot({ path: '/tmp/screenshot_start.png' });
    
    // Perform automation steps
    {{AUTOMATION_STEPS}}
    
    // Take final screenshot
    await page.screenshot({ path: '/tmp/screenshot_end.png' });
    
    console.log('Task completed successfully');
  } catch (error) {
    console.error('Error:', error.message);
    await page.screenshot({ path: '/tmp/screenshot_error.png' });
  } finally {
    await browser.close();
  }
})();
```

## Example Usage

```
automate browser to go to google and search for "Playwright tutorial"
use browser automation to fill out the contact form on example.com
control the browser to navigate to twitter and like the first post
scrape the top 5 headlines from news.ycombinator.com
```

## Notes

- Playwright supports Chromium, Firefox, and WebKit browsers
- Headless mode is faster but headed mode is useful for debugging
- Gemini Flash helps with intelligent decision-making for dynamic content
- Screenshots are saved to /tmp/ directory by default
- The skill combines deterministic automation (Playwright) with AI reasoning (Gemini)
- For complex tasks, the script can call Gemini Flash multiple times to adapt to page changes
- Handles modern web features: SPAs, dynamic content, AJAX, iframes, etc.

## Advanced Features

- **Vision-based automation**: Use Gemini Flash to analyze screenshots and decide next actions
- **Form filling**: Intelligently fill forms based on field labels and context
- **Data extraction**: Extract structured data from web pages
- **Multi-step workflows**: Chain multiple page interactions together
- **Error recovery**: Use Gemini to suggest alternative actions when automation fails
- **CAPTCHA detection**: Detect CAPTCHAs and alert user (cannot solve automatically)

## Dependencies

- Node.js (for running Playwright)
- Playwright npm package
- Chromium browser (installed via Playwright)
- Google Gemini API access (already available via gemini_code_execution)
