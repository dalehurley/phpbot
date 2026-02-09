#!/usr/bin/env python3
import subprocess
import json
from datetime import datetime
from email import policy
from email.parser import BytesParser

def get_unread_emails():
    """Get unread emails using AppleScript"""
    script = '''
    tell application "Mail"
        set unreadMessages to (every message of inbox whose read status is false)
        set emailList to {}
        
        repeat with msg in unreadMessages
            set emailInfo to {subject:(subject of msg), sender:(sender of msg), dateReceived:(date received of msg), content:(content of msg)}
            set end of emailList to emailInfo
        end repeat
        
        return emailList
    end tell
    '''
    
    try:
        result = subprocess.run(['osascript', '-e', script], capture_output=True, text=True, timeout=30)
        if result.returncode == 0:
            # Parse the AppleScript output
            output = result.stdout.strip()
            return output
        else:
            return None
    except Exception as e:
        print(f"Error: {e}")
        return None

# Get unread count first
count_script = '''
tell application "Mail"
    count (every message of inbox whose read status is false)
end tell
'''

result = subprocess.run(['osascript', '-e', count_script], capture_output=True, text=True)
unread_count = result.stdout.strip()

print(f"Total unread emails: {unread_count}")

# Get detailed info
emails = get_unread_emails()
if emails:
    print("\n" + "="*80)
    print("UNREAD EMAILS:")
    print("="*80)
    print(emails)
