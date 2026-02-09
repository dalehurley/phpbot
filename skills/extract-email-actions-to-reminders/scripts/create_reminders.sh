#!/bin/bash

echo "Creating reminders from email action items..."
echo ""

# High priority items
reminders add "Email To-Dos" "Pay Wahroonga After School Care bill (from Nicole)" \
  --priority high \
  --notes "Email from Jan 27, 2026 - Customer Account Statement. Nicole said 'Pls pay'"
echo "✓ Created: Pay Wahroonga After School Care bill"

reminders add "Email To-Dos" "URGENT: Upgrade Next.js immediately (CVE-2025-55182)" \
  --priority high \
  --due-date "today" \
  --notes "Security vulnerability - React2Shell - Email from Talha Tariq/Vercel - Dec 6, 2025. Action required!"
echo "✓ Created: URGENT Next.js upgrade"

reminders add "Email To-Dos" "WPS Stage 3 Party Swimming Day - permission required" \
  --priority high \
  --notes "Email from Wahroonga Public School - Dec 8, 2025. Need to provide permission."
echo "✓ Created: Swimming Day permission"

# Medium priority items
reminders add "Email To-Dos" "Process Medicare claim for Zak - North Shore Speech Therapy" \
  --priority medium \
  --notes "Email from Nov 10, 2025 - Receipt 452957. Nicole mentioned 'One last Medicare claim for Zak'"
echo "✓ Created: Medicare claim for Zak"

reminders add "Email To-Dos" "Review District Camp 2025 Registration" \
  --priority medium \
  --notes "Email from Nicole - Sep 15, 2025"
echo "✓ Created: District Camp registration"

reminders add "Email To-Dos" "Review Linkt toll statement" \
  --priority medium \
  --notes "Statement ready - Email from Dec 4, 2025"
echo "✓ Created: Linkt statement"

reminders add "Email To-Dos" "Review Laravel Forge December 2025 invoice" \
  --priority medium \
  --notes "Invoice available - Email from Dec 7, 2025"
echo "✓ Created: Laravel Forge invoice"

# Low priority items
reminders add "Email To-Dos" "Review Welcome Back to Term 1 2026 program" \
  --priority low \
  --notes "Email from Nicole - Feb 7, 2026"
echo "✓ Created: Term 1 2026 program"

reminders add "Email To-Dos" "Check Scouts schedule (7:15PM - 9:30PM at the Hall)" \
  --priority low \
  --notes "Email from Nicole - Dec 3, 2025"
echo "✓ Created: Scouts schedule"

reminders add "Email To-Dos" "Review Tigers Rugby website update" \
  --priority low \
  --notes "Email from Nicole/Comms WRC - Jan 31, 2026"
echo "✓ Created: Tigers Rugby website"

reminders add "Email To-Dos" "Review Domain Privacy Policy update" \
  --priority low \
  --notes "Effective Jan 5, 2026 - Email from Dec 7, 2025"
echo "✓ Created: Domain Privacy Policy"

reminders add "Email To-Dos" "Consider AI-Native Career Skills event" \
  --priority low \
  --notes "Sydney Startups meetup - Multiple emails Dec 6-8, 2025"
echo "✓ Created: AI-Native Career event"

reminders add "Email To-Dos" "Connect with David Billington on LinkedIn" \
  --priority low \
  --notes "Director of Operations - 39 mutual connections - Email from Dec 6, 2025"
echo "✓ Created: LinkedIn connection"

echo ""
echo "============================================================"
echo "✅ Successfully created 13 reminders in 'Email To-Dos' list"
echo "============================================================"