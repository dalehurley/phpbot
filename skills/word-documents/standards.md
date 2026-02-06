# Formatting Standards

Predefined formatting standards for common professional document types. These standards can be applied using `format.py` with the `-s` flag, or used as templates when creating custom standards.

## How to Use

### Apply a Built-in Standard

```bash
python3 scripts/format.py document.docx -s legal -o formatted.docx
```

### Apply a Custom Standard

Create a JSON file following the schema below, then:

```bash
python3 scripts/format.py document.docx --custom-standard my_standard.json -o formatted.docx
```

---

## Standard Schema

Each standard is a JSON object with these sections:

```json
{
  "name": "Human-readable name",
  "body": {
    "font_name": "Font family name",
    "font_size_pt": 12,
    "line_spacing": 2.0,
    "space_before_pt": 0,
    "space_after_pt": 0,
    "alignment": "justify",
    "first_line_indent_inches": 0.5
  },
  "heading1": {
    "font_name": "Font family name",
    "font_size_pt": 14,
    "bold": true,
    "italic": false,
    "alignment": "center",
    "space_before_pt": 24,
    "space_after_pt": 12,
    "all_caps": false,
    "color": "000000"
  },
  "heading2": { ... },
  "heading3": { ... },
  "page": {
    "margin_top_inches": 1.0,
    "margin_bottom_inches": 1.0,
    "margin_left_inches": 1.0,
    "margin_right_inches": 1.0
  }
}
```

### Alignment Values

- `"left"` - Left-aligned (ragged right)
- `"center"` - Centered
- `"right"` - Right-aligned
- `"justify"` - Justified (both edges aligned)

### Color Values

- 6-digit hex without `#` prefix: `"2E74B5"`, `"000000"`, `"CC0000"`

---

## Built-in Standards

### 1. Legal / Court Filing

Standard formatting for court filings, briefs, and legal memoranda in US jurisdictions.

```json
{
  "name": "Legal / Court Filing",
  "body": {
    "font_name": "Times New Roman",
    "font_size_pt": 12,
    "line_spacing": 2.0,
    "space_before_pt": 0,
    "space_after_pt": 0,
    "alignment": "justify",
    "first_line_indent_inches": 0.5
  },
  "heading1": {
    "font_name": "Times New Roman",
    "font_size_pt": 14,
    "bold": true,
    "alignment": "center",
    "space_before_pt": 24,
    "space_after_pt": 12,
    "all_caps": true
  },
  "heading2": {
    "font_name": "Times New Roman",
    "font_size_pt": 13,
    "bold": true,
    "alignment": "left",
    "space_before_pt": 18,
    "space_after_pt": 6
  },
  "heading3": {
    "font_name": "Times New Roman",
    "font_size_pt": 12,
    "bold": true,
    "italic": true,
    "alignment": "left",
    "space_before_pt": 12,
    "space_after_pt": 6
  },
  "page": {
    "margin_top_inches": 1.0,
    "margin_bottom_inches": 1.0,
    "margin_left_inches": 1.0,
    "margin_right_inches": 1.0
  }
}
```

**Key requirements:**

- Times New Roman, 12pt body text
- Double-spaced throughout
- 1-inch margins on all sides
- First line indent of 0.5 inches
- Justified text alignment
- Headings centered and in caps for main sections
- Many courts require line numbering (apply separately via `reference.md`)

**Common variations by jurisdiction:**

- Federal courts: Generally follow above
- California state courts: 28 lines per page, line numbering required
- New York state courts: Similar to federal, some courts require 14pt font
- Texas courts: 13pt font minimum in some jurisdictions

---

### 2. Academic (APA-Style)

Based on APA 7th Edition formatting guidelines.

```json
{
  "name": "Academic (APA-style)",
  "body": {
    "font_name": "Times New Roman",
    "font_size_pt": 12,
    "line_spacing": 2.0,
    "space_before_pt": 0,
    "space_after_pt": 0,
    "alignment": "left",
    "first_line_indent_inches": 0.5
  },
  "heading1": {
    "font_name": "Times New Roman",
    "font_size_pt": 12,
    "bold": true,
    "alignment": "center",
    "space_before_pt": 24,
    "space_after_pt": 12
  },
  "heading2": {
    "font_name": "Times New Roman",
    "font_size_pt": 12,
    "bold": true,
    "alignment": "left",
    "space_before_pt": 18,
    "space_after_pt": 6
  },
  "heading3": {
    "font_name": "Times New Roman",
    "font_size_pt": 12,
    "bold": true,
    "italic": true,
    "alignment": "left",
    "space_before_pt": 12,
    "space_after_pt": 6
  },
  "page": {
    "margin_top_inches": 1.0,
    "margin_bottom_inches": 1.0,
    "margin_left_inches": 1.0,
    "margin_right_inches": 1.0
  }
}
```

**Key requirements:**

- Times New Roman 12pt (APA 7 also allows Calibri 11pt, Arial 11pt, Georgia 11pt)
- Double-spaced throughout including references
- 1-inch margins
- Left-aligned (ragged right) -- APA does NOT use justified alignment
- First line indent 0.5 inches
- Running head on each page
- Headings are 12pt (same as body), differentiated by bold/italic/alignment

**APA Heading Levels:**

1. Level 1: Centered, Bold
2. Level 2: Flush Left, Bold
3. Level 3: Flush Left, Bold Italic
4. Level 4: Indented, Bold, ending with period
5. Level 5: Indented, Bold Italic, ending with period

---

### 3. Corporate / Business

Modern business document formatting suitable for reports, proposals, and memos.

```json
{
  "name": "Corporate / Business",
  "body": {
    "font_name": "Calibri",
    "font_size_pt": 11,
    "line_spacing": 1.15,
    "space_before_pt": 0,
    "space_after_pt": 8,
    "alignment": "left"
  },
  "heading1": {
    "font_name": "Calibri",
    "font_size_pt": 16,
    "bold": true,
    "color": "2E74B5",
    "alignment": "left",
    "space_before_pt": 24,
    "space_after_pt": 6
  },
  "heading2": {
    "font_name": "Calibri",
    "font_size_pt": 13,
    "bold": true,
    "color": "2E74B5",
    "alignment": "left",
    "space_before_pt": 18,
    "space_after_pt": 4
  },
  "heading3": {
    "font_name": "Calibri",
    "font_size_pt": 12,
    "bold": true,
    "color": "1F4D78",
    "alignment": "left",
    "space_before_pt": 12,
    "space_after_pt": 4
  },
  "page": {
    "margin_top_inches": 1.0,
    "margin_bottom_inches": 1.0,
    "margin_left_inches": 1.0,
    "margin_right_inches": 1.0
  }
}
```

**Key requirements:**

- Calibri 11pt (Microsoft Office default)
- 1.15 line spacing (compact and readable)
- 8pt paragraph spacing
- Blue accent headings for visual hierarchy
- Left-aligned
- Professional, clean appearance

---

### 4. Regulatory / Compliance

Formatting for government submissions, regulatory filings, and compliance documents.

```json
{
  "name": "Regulatory / Compliance",
  "body": {
    "font_name": "Arial",
    "font_size_pt": 11,
    "line_spacing": 1.5,
    "space_before_pt": 0,
    "space_after_pt": 6,
    "alignment": "justify"
  },
  "heading1": {
    "font_name": "Arial",
    "font_size_pt": 14,
    "bold": true,
    "alignment": "left",
    "space_before_pt": 24,
    "space_after_pt": 12,
    "all_caps": true
  },
  "heading2": {
    "font_name": "Arial",
    "font_size_pt": 12,
    "bold": true,
    "alignment": "left",
    "space_before_pt": 18,
    "space_after_pt": 6
  },
  "heading3": {
    "font_name": "Arial",
    "font_size_pt": 11,
    "bold": true,
    "alignment": "left",
    "space_before_pt": 12,
    "space_after_pt": 6
  },
  "page": {
    "margin_top_inches": 1.0,
    "margin_bottom_inches": 1.0,
    "margin_left_inches": 1.25,
    "margin_right_inches": 1.25
  }
}
```

**Key requirements:**

- Arial or sans-serif font for clarity
- 1.5 line spacing (compromise between readability and density)
- Justified alignment
- Wider left/right margins (1.25 inches) for binding
- All-caps section headings
- Structured, formal appearance

**Common regulatory contexts:**

- FDA submissions
- SEC filings (often have specific requirements per form type)
- EPA documentation
- Banking/insurance regulatory filings
- Government RFP responses

---

### 5. Accessible Document

Formatting optimized for accessibility and WCAG compliance.

```json
{
  "name": "Accessible Document",
  "body": {
    "font_name": "Arial",
    "font_size_pt": 12,
    "line_spacing": 1.5,
    "space_before_pt": 0,
    "space_after_pt": 8,
    "alignment": "left"
  },
  "heading1": {
    "font_name": "Arial",
    "font_size_pt": 18,
    "bold": true,
    "alignment": "left",
    "space_before_pt": 36,
    "space_after_pt": 12
  },
  "heading2": {
    "font_name": "Arial",
    "font_size_pt": 16,
    "bold": true,
    "alignment": "left",
    "space_before_pt": 24,
    "space_after_pt": 8
  },
  "heading3": {
    "font_name": "Arial",
    "font_size_pt": 14,
    "bold": true,
    "alignment": "left",
    "space_before_pt": 18,
    "space_after_pt": 6
  },
  "page": {
    "margin_top_inches": 1.0,
    "margin_bottom_inches": 1.0,
    "margin_left_inches": 1.0,
    "margin_right_inches": 1.0
  }
}
```

**Key requirements:**

- Sans-serif font (Arial) for screen readability
- Minimum 12pt body text
- 1.5 line spacing minimum
- Left-aligned (never justified -- uneven word spacing hurts readability)
- Clear size progression between heading levels (18/16/14/12)
- Generous spacing between sections
- Use built-in heading styles (essential for screen readers)
- Alt text on all images
- Proper table headers marked
- No color as the only means of conveying information

**Accessibility checklist:**

- [ ] All images have alt text
- [ ] Headings use built-in styles (not just bold/large text)
- [ ] Heading hierarchy is logical (H1 > H2 > H3, no skipping)
- [ ] Tables have header rows marked
- [ ] Links have descriptive text (not "click here")
- [ ] Color contrast ratio meets 4.5:1 minimum
- [ ] Document language is set
- [ ] Reading order is logical
- [ ] No blank paragraphs used for spacing

---

## Creating Custom Standards

### Minimal Custom Standard

```json
{
  "name": "My Company Standard",
  "body": {
    "font_name": "Georgia",
    "font_size_pt": 11,
    "line_spacing": 1.3
  },
  "heading1": {
    "font_name": "Georgia",
    "font_size_pt": 16,
    "bold": true
  },
  "page": {
    "margin_top_inches": 0.75,
    "margin_bottom_inches": 0.75,
    "margin_left_inches": 1.0,
    "margin_right_inches": 1.0
  }
}
```

Only include the properties you want to set. Omitted properties will retain their current values in the document.

### Company Branding Example

```json
{
  "name": "Acme Corp Brand Standard",
  "body": {
    "font_name": "Open Sans",
    "font_size_pt": 10.5,
    "line_spacing": 1.2,
    "space_after_pt": 6,
    "alignment": "left"
  },
  "heading1": {
    "font_name": "Montserrat",
    "font_size_pt": 20,
    "bold": true,
    "color": "1A3C6E",
    "space_before_pt": 30,
    "space_after_pt": 10
  },
  "heading2": {
    "font_name": "Montserrat",
    "font_size_pt": 15,
    "bold": true,
    "color": "2D6BA4",
    "space_before_pt": 20,
    "space_after_pt": 8
  },
  "heading3": {
    "font_name": "Montserrat",
    "font_size_pt": 12,
    "bold": true,
    "color": "3D8BC9",
    "space_before_pt": 14,
    "space_after_pt": 6
  },
  "page": {
    "margin_top_inches": 1.0,
    "margin_bottom_inches": 0.75,
    "margin_left_inches": 1.0,
    "margin_right_inches": 1.0
  }
}
```

### Law Firm Letterhead Standard

```json
{
  "name": "Smith & Associates Letterhead",
  "body": {
    "font_name": "Garamond",
    "font_size_pt": 12,
    "line_spacing": 1.5,
    "space_after_pt": 0,
    "alignment": "justify",
    "first_line_indent_inches": 0.0
  },
  "heading1": {
    "font_name": "Garamond",
    "font_size_pt": 14,
    "bold": true,
    "all_caps": true,
    "alignment": "center",
    "space_before_pt": 18,
    "space_after_pt": 12
  },
  "heading2": {
    "font_name": "Garamond",
    "font_size_pt": 12,
    "bold": true,
    "alignment": "left",
    "space_before_pt": 12,
    "space_after_pt": 6
  },
  "page": {
    "margin_top_inches": 1.5,
    "margin_bottom_inches": 1.0,
    "margin_left_inches": 1.25,
    "margin_right_inches": 1.0
  }
}
```
