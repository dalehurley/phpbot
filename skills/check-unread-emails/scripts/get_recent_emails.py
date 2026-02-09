#!/usr/bin/env python3
import subprocess
from datetime import datetime, timedelta

# Get the 30 most recent unread emails
script = '''
tell application "Mail"
    set unreadMessages to (every message of inbox whose read status is false)
    set sortedMessages to reverse of unreadMessages
    set recentEmails to {}
    set counter to 0
    
    repeat with msg in sortedMessages
        if counter < 30 then
            set msgSubject to subject of msg
            set msgSender to sender of msg
            set msgDate to date received of msg
            set msgSnippet to ""
            
            try
                set msgContent to content of msg as text
                if length of msgContent > 200 then
                    set msgSnippet to text 1 thru 200 of msgContent
                else
                    set msgSnippet to msgContent
                end if
            end try
            
            set emailInfo to "SUBJECT: " & msgSubject & "
FROM: " & msgSender & "
DATE: " & (msgDate as string) & "
PREVIEW: " & msgSnippet & "
---"
            
            set end of recentEmails to emailInfo
            set counter to counter + 1
        end if
    end repeat
    
    return recentEmails as text
end tell
'''

result = subprocess.run(['osascript', '-e', script], capture_output=True, text=True, timeout=60)
if result.returncode == 0:
    print(result.stdout)
else:
    print(f"Error: {result.stderr}")
