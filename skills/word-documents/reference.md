# Word Document Processing - Advanced Reference

This document covers advanced python-docx operations, direct OOXML manipulation, and detailed patterns for professional document workflows.

## Table of Contents

- [python-docx Library Basics](#python-docx-library-basics)
- [Style Management](#style-management)
- [Table Operations](#table-operations)
- [Headers and Footers](#headers-and-footers)
- [Sections and Page Layout](#sections-and-page-layout)
- [Images](#images)
- [Footnotes and Endnotes](#footnotes-and-endnotes)
- [Hyperlinks](#hyperlinks)
- [Tracked Changes XML](#tracked-changes-xml)
- [Comments XML](#comments-xml)
- [Document Protection](#document-protection)
- [Find and Replace](#find-and-replace)
- [Numbering and Lists](#numbering-and-lists)
- [Table of Contents](#table-of-contents)
- [Mail Merge Patterns](#mail-merge-patterns)
- [Pandoc Integration](#pandoc-integration)

---

## python-docx Library Basics

### Installation

```bash
pip install python-docx lxml
```

### Open and Save

```python
from docx import Document

# Open existing
doc = Document("input.docx")

# Create new
doc = Document()

# Save
doc.save("output.docx")
```

### Read All Text

```python
full_text = []
for para in doc.paragraphs:
    full_text.append(para.text)
print("\n".join(full_text))
```

### Read Tables

```python
for table in doc.tables:
    for row in table.rows:
        row_data = [cell.text for cell in row.cells]
        print(" | ".join(row_data))
```

### Core Properties (Metadata)

```python
props = doc.core_properties
props.author = "Jane Doe"
props.title = "Quarterly Report"
props.subject = "Finance"
props.keywords = "revenue, forecast"
props.category = "Reports"
props.comments = "Draft version"

# Read
print(f"Author: {props.author}")
print(f"Created: {props.created}")
print(f"Modified: {props.modified}")
print(f"Revision: {props.revision}")
```

---

## Style Management

### List Available Styles

```python
for style in doc.styles:
    print(f"{style.name} - Type: {style.type} - Built-in: {style.builtin}")
```

### Apply Existing Style

```python
para = doc.add_paragraph("Styled text")
para.style = doc.styles['Heading 1']
```

### Create Custom Style

```python
from docx.shared import Pt, RGBColor, Inches
from docx.enum.style import WD_STYLE_TYPE
from docx.enum.text import WD_ALIGN_PARAGRAPH

# Create paragraph style
style = doc.styles.add_style('CustomBody', WD_STYLE_TYPE.PARAGRAPH)
style.base_style = doc.styles['Normal']
style.font.name = 'Georgia'
style.font.size = Pt(11)
style.font.color.rgb = RGBColor(0x33, 0x33, 0x33)
style.paragraph_format.space_after = Pt(6)
style.paragraph_format.line_spacing = 1.5
style.paragraph_format.alignment = WD_ALIGN_PARAGRAPH.JUSTIFY

# Create character style
char_style = doc.styles.add_style('Highlight', WD_STYLE_TYPE.CHARACTER)
char_style.font.bold = True
char_style.font.color.rgb = RGBColor(0xCC, 0x00, 0x00)
```

### Modify Existing Style

```python
style = doc.styles['Normal']
style.font.name = 'Calibri'
style.font.size = Pt(11)
style.paragraph_format.space_after = Pt(8)
style.paragraph_format.line_spacing = 1.15
```

### Run-Level Formatting

```python
para = doc.add_paragraph()
run = para.add_run("Bold and large")
run.font.bold = True
run.font.size = Pt(16)
run.font.name = "Arial"
run.font.color.rgb = RGBColor(0x00, 0x00, 0xFF)
run.font.italic = True
run.font.underline = True
run.font.all_caps = True
run.font.strike = True
run.font.superscript = True   # or .subscript = True
```

---

## Table Operations

### Basic Table

```python
from docx.shared import Inches, Pt, Cm
from docx.enum.table import WD_ALIGN_VERTICAL

table = doc.add_table(rows=3, cols=3)
table.style = 'Table Grid'

# Populate cells
for i, row in enumerate(table.rows):
    for j, cell in enumerate(row.cells):
        cell.text = f"Row {i+1}, Col {j+1}"
```

### Set Column Widths

```python
from docx.shared import Inches

table = doc.add_table(rows=2, cols=3)
for row in table.rows:
    row.cells[0].width = Inches(1.5)
    row.cells[1].width = Inches(3.0)
    row.cells[2].width = Inches(2.0)
```

### Merge Cells

```python
# Merge horizontally
table.cell(0, 0).merge(table.cell(0, 2))  # Merge row 0, cols 0-2

# Merge vertically
table.cell(0, 0).merge(table.cell(2, 0))  # Merge col 0, rows 0-2
```

### Cell Formatting

```python
from docx.oxml.ns import qn
from docx.shared import Pt, RGBColor
from docx.enum.table import WD_ALIGN_VERTICAL

cell = table.cell(0, 0)

# Vertical alignment
cell.vertical_alignment = WD_ALIGN_VERTICAL.CENTER

# Background color (shading)
shading = cell._element.get_or_add_tcPr()
shading_elem = shading.makeelement(qn('w:shd'), {
    qn('w:val'): 'clear',
    qn('w:color'): 'auto',
    qn('w:fill'): '4472C4',
})
shading.append(shading_elem)

# Cell text formatting
para = cell.paragraphs[0]
run = para.runs[0] if para.runs else para.add_run(cell.text)
run.font.color.rgb = RGBColor(0xFF, 0xFF, 0xFF)
run.font.bold = True
```

### Nested Tables

```python
outer = doc.add_table(rows=1, cols=2)
outer.style = 'Table Grid'

# Add inner table to a cell
cell = outer.cell(0, 1)
inner = cell.add_table(rows=2, cols=2)
inner.style = 'Table Grid'
```

### Remove Table Borders

```python
from docx.oxml.ns import qn
from lxml import etree

for row in table.rows:
    for cell in row.cells:
        tc = cell._element
        tcPr = tc.get_or_add_tcPr()
        borders = tcPr.makeelement(qn('w:tcBorders'), {})
        for border_name in ['top', 'left', 'bottom', 'right']:
            border = borders.makeelement(qn(f'w:{border_name}'), {
                qn('w:val'): 'none',
                qn('w:sz'): '0',
                qn('w:space'): '0',
                qn('w:color'): 'auto',
            })
            borders.append(border)
        tcPr.append(borders)
```

---

## Headers and Footers

### Basic Header/Footer

```python
from docx.shared import Pt
from docx.enum.text import WD_ALIGN_PARAGRAPH

section = doc.sections[0]

# Header
header = section.header
header.is_linked_to_previous = False
header_para = header.paragraphs[0]
header_para.text = "Company Name - Confidential"
header_para.alignment = WD_ALIGN_PARAGRAPH.RIGHT
for run in header_para.runs:
    run.font.size = Pt(9)
    run.font.italic = True
```

### Page Numbers in Footer

```python
from docx.oxml.ns import qn
from lxml import etree

footer = section.footer
footer.is_linked_to_previous = False
para = footer.paragraphs[0]
para.alignment = WD_ALIGN_PARAGRAPH.CENTER

# Add "Page X of Y"
run1 = para.add_run("Page ")
run1.font.size = Pt(9)

# PAGE field
W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'
fld_begin = etree.SubElement(para._element, f'{{{W_NS}}}r')
etree.SubElement(fld_begin, f'{{{W_NS}}}fldChar').set(f'{{{W_NS}}}fldCharType', 'begin')
fld_code = etree.SubElement(para._element, f'{{{W_NS}}}r')
instr = etree.SubElement(fld_code, f'{{{W_NS}}}instrText')
instr.text = ' PAGE '
instr.set('{http://www.w3.org/XML/1998/namespace}space', 'preserve')
fld_end = etree.SubElement(para._element, f'{{{W_NS}}}r')
etree.SubElement(fld_end, f'{{{W_NS}}}fldChar').set(f'{{{W_NS}}}fldCharType', 'end')

run2 = para.add_run(" of ")
run2.font.size = Pt(9)

# NUMPAGES field
fld_begin2 = etree.SubElement(para._element, f'{{{W_NS}}}r')
etree.SubElement(fld_begin2, f'{{{W_NS}}}fldChar').set(f'{{{W_NS}}}fldCharType', 'begin')
fld_code2 = etree.SubElement(para._element, f'{{{W_NS}}}r')
instr2 = etree.SubElement(fld_code2, f'{{{W_NS}}}instrText')
instr2.text = ' NUMPAGES '
instr2.set('{http://www.w3.org/XML/1998/namespace}space', 'preserve')
fld_end2 = etree.SubElement(para._element, f'{{{W_NS}}}r')
etree.SubElement(fld_end2, f'{{{W_NS}}}fldChar').set(f'{{{W_NS}}}fldCharType', 'end')
```

### Different First Page Header

```python
section = doc.sections[0]
section.different_first_page_header_footer = True

# First page header
first_header = section.first_page_header
first_para = first_header.paragraphs[0]
first_para.text = "TITLE PAGE"

# Regular header (page 2+)
default_header = section.header
default_para = default_header.paragraphs[0]
default_para.text = "Running Header"
```

---

## Sections and Page Layout

### Page Margins

```python
from docx.shared import Inches

section = doc.sections[0]
section.top_margin = Inches(1.0)
section.bottom_margin = Inches(1.0)
section.left_margin = Inches(1.25)
section.right_margin = Inches(1.25)
```

### Page Orientation

```python
from docx.enum.section import WD_ORIENT
from docx.shared import Inches

section = doc.sections[0]
section.orientation = WD_ORIENT.LANDSCAPE
# Swap page dimensions
section.page_width = Inches(11)
section.page_height = Inches(8.5)
```

### Add New Section (Page Break)

```python
from docx.enum.section import WD_SECTION

doc.add_section(WD_SECTION.NEW_PAGE)
new_section = doc.sections[-1]
new_section.orientation = WD_ORIENT.PORTRAIT
```

### Multiple Columns

```python
from lxml import etree
from docx.oxml.ns import qn

section = doc.sections[0]
sectPr = section._sectPr
cols = sectPr.makeelement(qn('w:cols'), {qn('w:num'): '2', qn('w:space'): '720'})
sectPr.append(cols)
```

### Line Numbers (Legal Documents)

```python
from lxml import etree
from docx.oxml.ns import qn

sectPr = doc.sections[0]._sectPr
ln_num = sectPr.makeelement(qn('w:lnNumType'), {
    qn('w:countBy'): '1',
    qn('w:start'): '1',
    qn('w:restart'): 'newPage',
    qn('w:distance'): '360',  # distance from text in twips
})
sectPr.append(ln_num)
```

---

## Images

### Add Image

```python
from docx.shared import Inches

doc.add_picture('photo.jpg', width=Inches(4.0))

# Or within a paragraph
para = doc.add_paragraph()
run = para.add_run()
run.add_picture('logo.png', width=Inches(1.5), height=Inches(1.0))
```

### Add Image to Table Cell

```python
cell = table.cell(0, 0)
para = cell.paragraphs[0]
run = para.add_run()
run.add_picture('icon.png', width=Inches(0.5))
```

---

## Footnotes and Endnotes

python-docx does not have built-in footnote support, but you can add them via XML:

```python
from lxml import etree

W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'

def add_footnote(paragraph, footnote_text, footnote_id):
    """Add a footnote reference to a paragraph."""
    # Add footnote reference in document body
    run = etree.SubElement(paragraph._element, f'{{{W_NS}}}r')
    rpr = etree.SubElement(run, f'{{{W_NS}}}rPr')
    rstyle = etree.SubElement(rpr, f'{{{W_NS}}}rStyle')
    rstyle.set(f'{{{W_NS}}}val', 'FootnoteReference')
    fn_ref = etree.SubElement(run, f'{{{W_NS}}}footnoteReference')
    fn_ref.set(f'{{{W_NS}}}id', str(footnote_id))

    # The actual footnote content must be added to the footnotes.xml part
    # This requires accessing the package structure directly
```

---

## Hyperlinks

```python
from docx.oxml.ns import qn
from lxml import etree

def add_hyperlink(paragraph, url, text):
    """Add a hyperlink to a paragraph."""
    part = paragraph.part
    r_id = part.relate_to(
        url,
        'http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink',
        is_external=True
    )

    W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'
    R_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships'

    hyperlink = etree.SubElement(paragraph._element, f'{{{W_NS}}}hyperlink')
    hyperlink.set(f'{{{R_NS}}}id', r_id)

    run = etree.SubElement(hyperlink, f'{{{W_NS}}}r')
    rpr = etree.SubElement(run, f'{{{W_NS}}}rPr')
    rstyle = etree.SubElement(rpr, f'{{{W_NS}}}rStyle')
    rstyle.set(f'{{{W_NS}}}val', 'Hyperlink')

    t = etree.SubElement(run, f'{{{W_NS}}}t')
    t.text = text
    t.set('{http://www.w3.org/XML/1998/namespace}space', 'preserve')

    return hyperlink
```

---

## Tracked Changes XML

### OOXML Namespace

```python
W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'
```

### Insertion

```xml
<w:ins w:id="1" w:author="Author Name" w:date="2025-01-15T10:30:00Z">
  <w:r>
    <w:rPr><!-- preserve original formatting --></w:rPr>
    <w:t>inserted text</w:t>
  </w:r>
</w:ins>
```

### Deletion

```xml
<w:del w:id="2" w:author="Author Name" w:date="2025-01-15T10:30:00Z">
  <w:r>
    <w:rPr><!-- preserve original formatting --></w:rPr>
    <w:delText>deleted text</w:delText>
  </w:r>
</w:del>
```

### Format Change

```xml
<w:r>
  <w:rPr>
    <w:b/><!-- new formatting: bold -->
    <w:rPrChange w:id="3" w:author="Author Name" w:date="2025-01-15T10:30:00Z">
      <w:rPr>
        <!-- old formatting: not bold (empty) -->
      </w:rPr>
    </w:rPrChange>
  </w:rPr>
  <w:t>text with changed formatting</w:t>
</w:r>
```

### Paragraph Property Change

```xml
<w:pPr>
  <w:jc w:val="center"/><!-- new alignment -->
  <w:pPrChange w:id="4" w:author="Author Name" w:date="2025-01-15T10:30:00Z">
    <w:pPr>
      <w:jc w:val="left"/><!-- old alignment -->
    </w:pPr>
  </w:pPrChange>
</w:pPr>
```

### Accept Changes Programmatically

```python
from lxml import etree

W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'
body = doc.element.body

# Accept insertions (unwrap)
for ins in list(body.iter(f'{{{W_NS}}}ins')):
    parent = ins.getparent()
    idx = list(parent).index(ins)
    for i, child in enumerate(list(ins)):
        ins.remove(child)
        parent.insert(idx + i, child)
    parent.remove(ins)

# Accept deletions (remove)
for dele in list(body.iter(f'{{{W_NS}}}del')):
    dele.getparent().remove(dele)

# Accept format changes (remove rPrChange)
for rpc in list(body.iter(f'{{{W_NS}}}rPrChange')):
    rpc.getparent().remove(rpc)
```

---

## Comments XML

### Comment Structure

Comments are stored in `word/comments.xml`:

```xml
<w:comments xmlns:w="...">
  <w:comment w:id="0" w:author="Reviewer" w:date="2025-01-15T10:00:00Z" w:initials="R">
    <w:p>
      <w:r>
        <w:rPr><w:rStyle w:val="CommentReference"/></w:rPr>
        <w:annotationRef/>
      </w:r>
      <w:r>
        <w:t>This needs clarification.</w:t>
      </w:r>
    </w:p>
  </w:comment>
</w:comments>
```

### Comment Markers in Document Body

```xml
<w:p>
  <w:commentRangeStart w:id="0"/>
  <w:r><w:t>commented text here</w:t></w:r>
  <w:commentRangeEnd w:id="0"/>
  <w:r>
    <w:rPr><w:rStyle w:val="CommentReference"/></w:rPr>
    <w:commentReference w:id="0"/>
  </w:r>
</w:p>
```

### Read Comments Programmatically

```python
from lxml import etree

W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'

# Find comments part
for rel in doc.part.rels.values():
    if 'comments' in rel.reltype and 'Extended' not in rel.reltype:
        root = etree.fromstring(rel.target_part.blob)
        for comment in root.findall(f'{{{W_NS}}}comment'):
            comment_id = comment.get(f'{{{W_NS}}}id')
            author = comment.get(f'{{{W_NS}}}author')
            text_parts = []
            for t in comment.iter(f'{{{W_NS}}}t'):
                if t.text:
                    text_parts.append(t.text)
            print(f"[{author}] {' '.join(text_parts)}")
```

---

## Document Protection

### Read-Only Protection

```python
from lxml import etree
from docx.oxml.ns import qn

settings = doc.settings.element
protection = settings.makeelement(qn('w:documentProtection'), {
    qn('w:edit'): 'readOnly',
    qn('w:enforcement'): '1',
})
settings.append(protection)
```

### Allow Only Comments

```python
protection = settings.makeelement(qn('w:documentProtection'), {
    qn('w:edit'): 'comments',
    qn('w:enforcement'): '1',
})
settings.append(protection)
```

### Allow Only Track Changes

```python
protection = settings.makeelement(qn('w:documentProtection'), {
    qn('w:edit'): 'trackedChanges',
    qn('w:enforcement'): '1',
})
settings.append(protection)
```

---

## Find and Replace

### Simple Find and Replace

```python
def find_and_replace(doc, old_text, new_text):
    """Replace text across paragraphs."""
    for para in doc.paragraphs:
        if old_text in para.text:
            for run in para.runs:
                if old_text in run.text:
                    run.text = run.text.replace(old_text, new_text)

    # Also check tables
    for table in doc.tables:
        for row in table.rows:
            for cell in row.cells:
                for para in cell.paragraphs:
                    if old_text in para.text:
                        for run in para.runs:
                            if old_text in run.text:
                                run.text = run.text.replace(old_text, new_text)
```

### Find and Replace with Formatting

```python
from docx.shared import RGBColor

def find_and_highlight(doc, search_text, highlight_color=RGBColor(0xFF, 0xFF, 0x00)):
    """Find text and apply highlight color."""
    for para in doc.paragraphs:
        for run in para.runs:
            if search_text in run.text:
                run.font.highlight_color = 7  # Yellow (WD_COLOR_INDEX)
```

### Regex Find and Replace

```python
import re

def regex_replace(doc, pattern, replacement):
    """Regex-based find and replace (paragraph-level)."""
    for para in doc.paragraphs:
        full_text = para.text
        if re.search(pattern, full_text):
            # Simple case: single run
            if len(para.runs) == 1:
                para.runs[0].text = re.sub(pattern, replacement, para.runs[0].text)
            else:
                # Complex case: text spans multiple runs
                # Clear all runs and set text on first
                new_text = re.sub(pattern, replacement, full_text)
                for i, run in enumerate(para.runs):
                    if i == 0:
                        run.text = new_text
                    else:
                        run.text = ""
```

---

## Numbering and Lists

### Create Custom List

```python
from lxml import etree
from docx.oxml.ns import qn

W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'

# Access numbering part
numbering_part = doc.part.numbering_part

# Create new abstract numbering
abstract_num = etree.SubElement(numbering_part.element, f'{{{W_NS}}}abstractNum')
abstract_num.set(f'{{{W_NS}}}abstractNumId', '10')

# Level 0 (first level)
lvl = etree.SubElement(abstract_num, f'{{{W_NS}}}lvl')
lvl.set(f'{{{W_NS}}}ilvl', '0')

start = etree.SubElement(lvl, f'{{{W_NS}}}start')
start.set(f'{{{W_NS}}}val', '1')

num_fmt = etree.SubElement(lvl, f'{{{W_NS}}}numFmt')
num_fmt.set(f'{{{W_NS}}}val', 'decimal')

lvl_text = etree.SubElement(lvl, f'{{{W_NS}}}lvlText')
lvl_text.set(f'{{{W_NS}}}val', '%1.')

# Level 1 (sub-items)
lvl1 = etree.SubElement(abstract_num, f'{{{W_NS}}}lvl')
lvl1.set(f'{{{W_NS}}}ilvl', '1')

start1 = etree.SubElement(lvl1, f'{{{W_NS}}}start')
start1.set(f'{{{W_NS}}}val', '1')

num_fmt1 = etree.SubElement(lvl1, f'{{{W_NS}}}numFmt')
num_fmt1.set(f'{{{W_NS}}}val', 'lowerLetter')

lvl_text1 = etree.SubElement(lvl1, f'{{{W_NS}}}lvlText')
lvl_text1.set(f'{{{W_NS}}}val', '%2)')
```

---

## Table of Contents

python-docx can insert a TOC field but cannot generate/update it (Word does that on open):

```python
from docx.oxml.ns import qn
from lxml import etree

W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'

paragraph = doc.add_paragraph()
run = paragraph.add_run()

# Begin field
fld_begin = etree.SubElement(run._element, f'{{{W_NS}}}fldChar')
fld_begin.set(f'{{{W_NS}}}fldCharType', 'begin')

# Field code
run2 = paragraph.add_run()
instr = etree.SubElement(run2._element, f'{{{W_NS}}}instrText')
instr.text = r' TOC \o "1-3" \h \z \u '
instr.set('{http://www.w3.org/XML/1998/namespace}space', 'preserve')

# Separate
run3 = paragraph.add_run()
fld_sep = etree.SubElement(run3._element, f'{{{W_NS}}}fldChar')
fld_sep.set(f'{{{W_NS}}}fldCharType', 'separate')

# Placeholder text
run4 = paragraph.add_run("Right-click to update table of contents")

# End field
run5 = paragraph.add_run()
fld_end = etree.SubElement(run5._element, f'{{{W_NS}}}fldChar')
fld_end.set(f'{{{W_NS}}}fldCharType', 'end')
```

---

## Mail Merge Patterns

### Simple Template Filling

```python
def fill_template(template_path, output_path, data):
    """Fill a DOCX template with data using simple {{placeholder}} syntax."""
    doc = Document(template_path)

    for para in doc.paragraphs:
        for key, value in data.items():
            placeholder = "{{" + key + "}}"
            if placeholder in para.text:
                for run in para.runs:
                    if placeholder in run.text:
                        run.text = run.text.replace(placeholder, str(value))

    # Also handle tables
    for table in doc.tables:
        for row in table.rows:
            for cell in row.cells:
                for para in cell.paragraphs:
                    for key, value in data.items():
                        placeholder = "{{" + key + "}}"
                        if placeholder in para.text:
                            for run in para.runs:
                                if placeholder in run.text:
                                    run.text = run.text.replace(placeholder, str(value))

    doc.save(output_path)

# Usage
fill_template("template.docx", "output.docx", {
    "name": "Jane Doe",
    "date": "January 15, 2025",
    "amount": "$50,000",
    "company": "Acme Corp",
})
```

### Batch Mail Merge

```python
import csv

def batch_merge(template_path, csv_path, output_dir):
    """Generate one document per row in a CSV."""
    os.makedirs(output_dir, exist_ok=True)

    with open(csv_path, 'r') as f:
        reader = csv.DictReader(f)
        for i, row in enumerate(reader):
            output_path = os.path.join(output_dir, f"document_{i+1}.docx")
            fill_template(template_path, output_path, dict(row))
            print(f"Generated: {output_path}")
```

---

## Pandoc Integration

### Markdown to DOCX with Custom Styles

```bash
# Using a reference document for consistent styling
pandoc input.md -o output.docx --reference-doc=template.docx

# Create a reference document from defaults
pandoc -o custom-reference.docx --print-default-data-file reference.docx
```

### DOCX to Markdown with Track Changes

```bash
# Show all track changes in output
pandoc --track-changes=all input.docx -o output.md

# Accept all changes
pandoc --track-changes=accept input.docx -o output.md

# Reject all changes
pandoc --track-changes=reject input.docx -o output.md
```

### DOCX to PDF

```bash
# Via LaTeX (best quality, requires TeX installation)
pandoc input.docx -o output.pdf --pdf-engine=xelatex

# Via wkhtmltopdf (if available)
pandoc input.docx -o output.pdf --pdf-engine=wkhtmltopdf
```

### Extract Media from DOCX

```bash
pandoc input.docx --extract-media=./media -o /dev/null
```

---

## Useful Constants

### Units

| Unit         | Value      | Description            |
| ------------ | ---------- | ---------------------- |
| 1 inch       | 914400 EMU | English Metric Units   |
| 1 inch       | 1440 twips | Twentieth of a point   |
| 1 inch       | 72 pt      | Points                 |
| 1 cm         | 360000 EMU |                        |
| 1 pt         | 12700 EMU  |                        |
| 1 half-point | 6350 EMU   | python-docx font sizes |

### Common Page Sizes (inches)

| Paper     | Width | Height |
| --------- | ----- | ------ |
| US Letter | 8.5   | 11     |
| US Legal  | 8.5   | 14     |
| A4        | 8.27  | 11.69  |
| A3        | 11.69 | 16.54  |

### python-docx Shared Types

```python
from docx.shared import Inches, Cm, Mm, Pt, Emu, Twips, RGBColor

# All convert to EMU internally
width = Inches(2)       # 2 inches
width = Cm(5)           # 5 centimeters
width = Mm(25)          # 25 millimeters
width = Pt(72)          # 72 points (= 1 inch)
width = Emu(914400)     # 914400 EMU (= 1 inch)
width = Twips(1440)     # 1440 twips (= 1 inch)

color = RGBColor(0xFF, 0x00, 0x00)  # Red
color = RGBColor.from_string('0000FF')  # Blue
```
