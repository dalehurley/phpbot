# Browser Automation Skill

This skill enables PHPBot to automate web browser interactions using **Playwright** combined with **Google Gemini Flash** for intelligent decision-making.

## Overview

The browser automation skill provides:

- **Deterministic Automation**: Use Playwright for reliable browser control
- **AI-Powered Intelligence**: Use Gemini Flash to analyze pages and make smart decisions
- **Visual Understanding**: Take screenshots and use Gemini's vision capabilities
- **Adaptive Behavior**: Handle dynamic content and unexpected page layouts
- **Multi-Step Workflows**: Chain complex automation sequences

## Features

### Core Capabilities

1. **Navigation**: Go to URLs, follow links, handle redirects
2. **Form Interaction**: Fill inputs, select dropdowns, submit forms
3. **Element Interaction**: Click buttons, hover elements, drag and drop
4. **Data Extraction**: Scrape text, tables, lists, structured data
5. **Screenshot Capture**: Visual documentation at each step
6. **Error Handling**: Intelligent recovery from failures

### Gemini Integration

- **Page Analysis**: Understand page structure and content
- **Decision Making**: Choose optimal selectors and actions
- **Natural Language**: Convert user instructions to automation steps
- **Visual Reasoning**: Analyze screenshots to determine next actions
- **Error Recovery**: Suggest alternatives when automation fails

## Files

- **skill.md**: Skill definition and metadata
- **playwright_template.js**: Basic Playwright automation template
- **gemini_enhanced_browser.js**: Advanced template with Gemini integration
- **README.md**: This documentation

## Usage Examples

### Simple Navigation and Search

```
User: "Use browser automation to search Google for 'Playwright documentation'"
```

PHPBot will:
1. Launch Chromium browser
2. Navigate to google.com
3. Find the search input
4. Enter the query
5. Submit the search
6. Take screenshots
7. Report results

### Form Filling

```
User: "Automate filling out the contact form on example.com"
```

PHPBot will:
1. Navigate to the URL
2. Use Gemini to analyze form fields
3. Intelligently fill appropriate values
4. Submit the form
5. Capture confirmation

### Data Scraping

```
User: "Extract the top 10 headlines from Hacker News"
```

PHPBot will:
1. Navigate to news.ycombinator.com
2. Use Gemini to identify headline elements
3. Extract text and links
4. Return structured data

### Complex Multi-Step Task

```
User: "Go to GitHub, search for 'playwright', and star the first repository"
```

PHPBot will:
1. Navigate to github.com
2. Find and use search
3. Analyze results with Gemini
4. Click the first repository
5. Find and click the star button
6. Confirm action completed

## How It Works

### Workflow

1. **Task Analysis**
   - Parse user's natural language request
   - Use Gemini to break down into steps
   - Generate automation plan

2. **Browser Setup**
   - Check if Playwright is installed
   - Install if needed: `npm install -g playwright`
   - Install browser: `playwright install chromium`

3. **Script Generation**
   - Create Playwright script based on task
   - Include Gemini integration points
   - Add error handling and screenshots

4. **Execution Loop**
   - Run Playwright script
   - At decision points, capture screenshot
   - Send to Gemini for analysis
   - Execute recommended actions
   - Repeat until task complete

5. **Result Reporting**
   - Summarize what was accomplished
   - Show screenshots
   - Return extracted data
   - Report any errors

### Gemini Integration Points

The skill uses Gemini Flash at multiple stages:

1. **Pre-execution**: Analyze task and generate step plan
2. **During execution**: Make decisions about selectors and actions
3. **Visual analysis**: Understand page layout from screenshots
4. **Error recovery**: Suggest alternatives when actions fail
5. **Data extraction**: Identify and structure scraped content

## Installation

### Prerequisites

- Node.js (v14 or higher)
- npm (comes with Node.js)

### Install Playwright

```bash
# Install Playwright globally
npm install -g playwright

# Install Chromium browser
playwright install chromium

# Verify installation
playwright --version
```

### Alternative: Local Installation

```bash
# Install in project directory
npm init -y
npm install playwright

# Install browsers
npx playwright install chromium
```

## Configuration

### Headless vs Headed Mode

- **Headless** (default for production): Browser runs invisibly, faster
- **Headed** (better for debugging): Browser window is visible

Set in the script:
```javascript
const config = {
  headless: false  // Set to true for headless
};
```

### Timeout Settings

Adjust timeouts for slow pages:
```javascript
const config = {
  timeout: 60000  // 60 seconds
};
```

### Screenshot Directory

Change where screenshots are saved:
```javascript
const config = {
  screenshotDir: '/tmp/playwright_screenshots'
};
```

## Advanced Usage

### Custom Selectors

The skill uses multiple strategies to find elements:

1. Text content: `text=Click Me`
2. CSS selectors: `button.submit`
3. XPath: `//button[@type='submit']`
4. ARIA labels: `[aria-label="Submit"]`
5. Playwright locators: `page.getByRole('button', { name: 'Submit' })`

### Waiting Strategies

- `waitForLoadState('networkidle')`: Wait for network to be idle
- `waitForSelector()`: Wait for element to appear
- `waitForTimeout()`: Fixed delay (use sparingly)
- `waitForNavigation()`: Wait for page navigation

### Error Handling

The script includes comprehensive error handling:

- Try multiple selector strategies
- Take screenshots on errors
- Log detailed error information
- Gracefully close browser
- Return meaningful error messages

## Gemini Prompt Examples

### Page Analysis Prompt

```
Analyze this browser screenshot and page context.
URL: https://example.com
Title: Example Page
Task: Fill out the contact form

Page elements:
- 3 input fields visible
- 1 textarea visible
- 1 submit button

Provide a JSON array of automation steps with:
{type: 'fill|click|wait', selector: '...', value: '...'}
```

### Decision Making Prompt

```
I'm trying to automate: "Click the login button"
Here's what I see on the page:
- Button with text "Sign In"
- Link with text "Log In"
- Button with text "Enter"

Which element should I click? Provide the best selector.
```

### Error Recovery Prompt

```
Automation failed with error: "Timeout waiting for selector 'button.submit'"

Page screenshot attached.
Task: Submit the contact form

What alternative selectors or approaches should I try?
```

## Troubleshooting

### Playwright Not Found

```bash
# Install globally
npm install -g playwright

# Or use npx
npx playwright install
```

### Browser Not Installed

```bash
# Install Chromium
playwright install chromium

# Or install all browsers
playwright install
```

### Timeout Errors

- Increase timeout in config
- Use `waitForLoadState('domcontentloaded')` instead of `'networkidle'`
- Add explicit waits: `await page.waitForTimeout(2000)`

### Selector Not Found

- Use Gemini to analyze page structure
- Try multiple selector strategies
- Use Playwright's `getByRole()` and `getByText()` methods
- Inspect page with browser DevTools

### Screenshots Not Saving

- Check directory exists: `mkdir -p /tmp/playwright_screenshots`
- Verify write permissions
- Use absolute paths

## Security Considerations

- Never automate login with real credentials in scripts
- Be respectful of rate limits and robots.txt
- Don't automate CAPTCHAs (they're designed to prevent automation)
- Consider website terms of service before scraping
- Use appropriate user agents
- Implement delays between requests

## Performance Tips

- Use headless mode for faster execution
- Minimize screenshots (only at key points)
- Use `waitForLoadState('domcontentloaded')` instead of `'networkidle'`
- Reuse browser context for multiple pages
- Close browser properly to free resources

## Future Enhancements

- [ ] Support for Firefox and WebKit browsers
- [ ] Video recording of automation sessions
- [ ] Network request interception and mocking
- [ ] Cookie and session management
- [ ] Proxy support
- [ ] Multi-tab automation
- [ ] Parallel browser instances
- [ ] Integration with browser DevTools Protocol
- [ ] Custom Playwright fixtures
- [ ] Test generation from automation sessions

## Resources

- [Playwright Documentation](https://playwright.dev)
- [Gemini API Documentation](https://ai.google.dev/docs)
- [Playwright Best Practices](https://playwright.dev/docs/best-practices)
- [Browser Automation Patterns](https://playwright.dev/docs/patterns)

## License

Part of PHPBot. See main project license.
