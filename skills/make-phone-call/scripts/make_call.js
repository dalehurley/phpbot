#!/usr/bin/env node

// Twilio phone call script
const accountSid = process.env.TWILIO_ACCOUNT_SID;
const authToken = {{API_SECRET}};
const fromNumber = process.env.TWILIO_PHONE_NUMBER;
const toNumber = process.argv[2];
const message = process.argv[3];

if (!accountSid || !authToken || !fromNumber) {
  console.error('Missing required environment variables:');
  console.error('- TWILIO_ACCOUNT_SID');
  console.error('- TWILIO_AUTH_TOKEN');
  console.error('- TWILIO_PHONE_NUMBER');
  process.exit(1);
}

if (!toNumber || !message) {
  console.error('Usage: node make_call.js <to_number> <message>');
  process.exit(1);
}

const twilio = require('twilio');
const client = twilio(accountSid, authToken);

console.log(`Initiating call to ${toNumber}...`);
console.log(`Message: "${message}"`);

client.calls
  .create({
    twiml: `<Response><Say voice="alice">${message}</Say></Response>`,
    to: toNumber,
    from: fromNumber
  })
  .then(call => {
    console.log(`✓ Call initiated successfully!`);
    console.log(`Call SID: ${call.sid}`);
    console.log(`Status: ${call.status}`);
  })
  .catch(error => {
    console.error('✗ Call failed:', error.message);
    process.exit(1);
  });