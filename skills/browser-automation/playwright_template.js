/**
 * Playwright Browser Automation Template
 * 
 * This template is used by PHPBot's browser-automation skill to control
 * web browsers using Playwright with Gemini Flash AI assistance.
 * 
 * Usage: node playwright_template.js <task> <url> <headless>
 */

const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

// Parse command line arguments
const args = process.argv.slice(2);
const task = args[0] || 'Navigate to the page';
const startUrl = args[1] || 'https://www.google.com';
const headless = args[2] === 'true';

// Configuration
const config = {
  headless: headless,
  timeout: 60000,
  screenshotDir: '/tmp/playwright_screenshots',
  viewport: { width: 1280, height: 720 }
};

// Create screenshot directory
if (!fs.existsSync(config.screenshotDir)) {
  fs.mkdirSync(config.screenshotDir, { recursive: true });
}

// Helper function to take timestamped screenshots
function getScreenshotPath(name) {
  const timestamp = Date.now();
  return path.join(config.screenshotDir, `${name}_${timestamp}.png`);
}

// Main automation function
(async () => {
  console.log('=== Playwright Browser Automation ===');
  console.log(`Task: ${task}`);
  console.log(`Starting URL: ${startUrl}`);
  console.log(`Headless: ${headless}`);
  console.log('=====================================\n');

  const browser = await chromium.launch({ 
    headless: config.headless,
    args: ['--no-sandbox', '--disable-setuid-sandbox']
  });
  
  const context = await browser.newContext({
    viewport: config.viewport,
    userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
  });
  
  const page = await context.newPage();
  
  // Set default timeout
  page.setDefaultTimeout(config.timeout);

  try {
    console.log(`Navigating to: ${startUrl}`);
    await page.goto(startUrl, { waitUntil: 'domcontentloaded' });
    
    // Wait for page to be ready
    await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {
      console.log('Network not idle after 10s, continuing anyway...');
    });
    
    const screenshotStart = getScreenshotPath('start');
    await page.screenshot({ path: screenshotStart, fullPage: false });
    console.log(`Screenshot saved: ${screenshotStart}`);
    
    // Get page title and URL
    const title = await page.title();
    const url = page.url();
    console.log(`Page title: ${title}`);
    console.log(`Current URL: ${url}`);
    
    // This is where Gemini Flash would provide intelligent automation steps
    // For now, we'll include basic examples that can be customized
    
    console.log('\n=== Executing automation task ===');
    
    // Example: If task involves search
    if (task.toLowerCase().includes('search')) {
      console.log('Detected search task...');
      
      // Try to find search input
      const searchSelectors = [
        'input[name="q"]',
        'input[type="search"]',
        'input[placeholder*="Search"]',
        'input[aria-label*="Search"]',
        '#search',
        '.search-input'
      ];
      
      let searchInput = null;
      for (const selector of searchSelectors) {
        try {
          searchInput = await page.waitForSelector(selector, { timeout: 2000 });
          if (searchInput) {
            console.log(`Found search input: ${selector}`);
            break;
          }
        } catch (e) {
          // Try next selector
        }
      }
      
      if (searchInput) {
        // Extract search query from task
        const queryMatch = task.match(/search (?:for )?["']?([^"']+)["']?/i);
        const query = queryMatch ? queryMatch[1] : 'test query';
        
        console.log(`Entering search query: ${query}`);
        await searchInput.fill(query);
        await page.keyboard.press('Enter');
        
        // Wait for results
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(2000); // Wait for dynamic content
        
        const screenshotResults = getScreenshotPath('search_results');
        await page.screenshot({ path: screenshotResults, fullPage: false });
        console.log(`Search results screenshot: ${screenshotResults}`);
      } else {
        console.log('Could not find search input');
      }
    }
    
    // Example: If task involves clicking
    if (task.toLowerCase().includes('click')) {
      console.log('Detected click task...');
      
      // Extract what to click from task
      const clickMatch = task.match(/click (?:on |the )?["']?([^"']+)["']?/i);
      const clickTarget = clickMatch ? clickMatch[1] : null;
      
      if (clickTarget) {
        console.log(`Looking for element to click: ${clickTarget}`);
        
        // Try multiple strategies to find the element
        const strategies = [
          `text=${clickTarget}`,
          `button:has-text("${clickTarget}")`,
          `a:has-text("${clickTarget}")`,
          `[aria-label*="${clickTarget}"]`,
          `[title*="${clickTarget}"]`
        ];
        
        for (const selector of strategies) {
          try {
            const element = await page.waitForSelector(selector, { timeout: 2000 });
            if (element) {
              console.log(`Found element with selector: ${selector}`);
              await element.click();
              console.log('Clicked successfully');
              await page.waitForTimeout(2000);
              break;
            }
          } catch (e) {
            // Try next strategy
          }
        }
      }
    }
    
    // Example: If task involves form filling
    if (task.toLowerCase().includes('fill') || task.toLowerCase().includes('form')) {
      console.log('Detected form filling task...');
      
      // Find all input fields
      const inputs = await page.$$('input[type="text"], input[type="email"], textarea');
      console.log(`Found ${inputs.length} input fields`);
      
      // This would be enhanced by Gemini to intelligently fill based on labels
      for (let i = 0; i < Math.min(inputs.length, 3); i++) {
        const input = inputs[i];
        const name = await input.getAttribute('name');
        const placeholder = await input.getAttribute('placeholder');
        console.log(`Input field: name="${name}", placeholder="${placeholder}"`);
      }
    }
    
    // Example: If task involves data extraction
    if (task.toLowerCase().includes('extract') || task.toLowerCase().includes('scrape')) {
      console.log('Detected data extraction task...');
      
      // Extract common elements
      const headlines = await page.$$eval('h1, h2, h3', elements => 
        elements.slice(0, 5).map(el => el.textContent.trim())
      );
      
      const links = await page.$$eval('a[href]', elements =>
        elements.slice(0, 10).map(el => ({
          text: el.textContent.trim(),
          href: el.href
        }))
      );
      
      console.log('\n=== Extracted Data ===');
      console.log('Headlines:', JSON.stringify(headlines, null, 2));
      console.log('Links:', JSON.stringify(links, null, 2));
    }
    
    // Take final screenshot
    const screenshotEnd = getScreenshotPath('end');
    await page.screenshot({ path: screenshotEnd, fullPage: true });
    console.log(`\nFinal screenshot saved: ${screenshotEnd}`);
    
    // Get final page state
    const finalTitle = await page.title();
    const finalUrl = page.url();
    
    console.log('\n=== Task Completed Successfully ===');
    console.log(`Final page title: ${finalTitle}`);
    console.log(`Final URL: ${finalUrl}`);
    console.log(`Screenshots saved to: ${config.screenshotDir}`);
    
  } catch (error) {
    console.error('\n=== Error Occurred ===');
    console.error('Error:', error.message);
    console.error('Stack:', error.stack);
    
    // Take error screenshot
    try {
      const screenshotError = getScreenshotPath('error');
      await page.screenshot({ path: screenshotError, fullPage: true });
      console.error(`Error screenshot saved: ${screenshotError}`);
    } catch (screenshotError) {
      console.error('Could not take error screenshot:', screenshotError.message);
    }
    
    process.exit(1);
  } finally {
    await browser.close();
    console.log('\nBrowser closed.');
  }
})();
