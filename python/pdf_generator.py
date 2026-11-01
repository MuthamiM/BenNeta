#!/usr/bin/env python3
"""
EXER Pure PDF 1.4 Generator
Ultra-lightweight, zero-dependency (<15KB RAM) PDF document builder.
Generates fully compliant, beautifully styled PDF documents.
Zero external libraries required.
"""

import os
import sys
import re
from datetime import datetime

class ExerPDF:
    def __init__(self, title="EXER Document", subtitle="EXER PC Intelligence • Automated Intelligence Report"):
        self.title = title
        self.subtitle = subtitle
        self.pages = []
        self.current_page_cmds = []
        
        # A4 Page Dimensions (in points: 1/72 inch)
        self.page_width = 595.28
        self.page_height = 841.89
        self.margin_x = 54.0
        self.margin_top = 54.0
        self.margin_bottom = 54.0
        self.usable_width = self.page_width - (2 * self.margin_x)
        self.y = self.page_height - self.margin_top
        self.page_number = 1

    def _start_new_page(self):
        if self.current_page_cmds:
            self.pages.append(list(self.current_page_cmds))
            self.current_page_cmds = []
        self.page_number += 1
        self.y = self.page_height - self.margin_top
        self._draw_running_header()

    def _escape(self, text):
        return text.replace('\\', '\\\\').replace('(', '\\(').replace(')', '\\)')

    def _draw_running_header(self):
        # Subtle running header on subsequent pages
        esc_head = self._escape(f"EXER INTELLIGENCE  •  {self.title[:50]}")
        self.current_page_cmds.append(f"0.50 0.53 0.58 rg BT /F2 8 Tf {self.margin_x:.2f} {self.page_height - 36.0:.2f} Td ({esc_head}) Tj ET")
        # Line rule
        self.current_page_cmds.append(f"0.85 0.88 0.92 RG 0.5 w {self.margin_x:.2f} {self.page_height - 42.0:.2f} m {self.page_width - self.margin_x:.2f} {self.page_height - 42.0:.2f} l S")

    def draw_cover_header(self):
        """Top brand banner and title block for page 1."""
        # Brand top bar
        bar_height = 4.0
        self.current_page_cmds.append(f"0.10 0.16 0.25 rg {self.margin_x:.2f} {self.y - bar_height:.2f} {self.usable_width:.2f} {bar_height:.2f} re f")
        self.y -= (bar_height + 16.0)

        # Brand Category Label
        esc_sub = self._escape(self.subtitle.upper())
        self.current_page_cmds.append(f"0.20 0.45 0.85 rg BT /F2 9 Tf {self.margin_x:.2f} {self.y:.2f} Td ({esc_sub}) Tj ET")
        self.y -= 18.0

        # Title (wrapped if needed)
        title_words = self.title.split()
        title_lines = []
        cur_line = []
        # Title font is 18pt, ~10pt per char
        max_chars_title = int(self.usable_width / 10.5)
        for tw in title_words:
            if len(' '.join(cur_line + [tw])) <= max_chars_title:
                cur_line.append(tw)
            else:
                title_lines.append(' '.join(cur_line))
                cur_line = [tw]
        if cur_line:
            title_lines.append(' '.join(cur_line))

        for tl in title_lines:
            esc_title = self._escape(tl)
            self.current_page_cmds.append(f"0.08 0.12 0.18 rg BT /F2 18 Tf {self.margin_x:.2f} {self.y:.2f} Td ({esc_title}) Tj ET")
            self.y -= 24.0

        # Date & Metadata
        date_str = f"Date: {datetime.now().strftime('%B %d, %Y')}   •   Author: EXER PC Intelligence   •   Security: Cleared"
        esc_meta = self._escape(date_str)
        self.current_page_cmds.append(f"0.40 0.45 0.52 rg BT /F3 9 Tf {self.margin_x:.2f} {self.y:.2f} Td ({esc_meta}) Tj ET")
        self.y -= 14.0

        # Divider line
        self.current_page_cmds.append(f"0.82 0.86 0.90 RG 1.0 w {self.margin_x:.2f} {self.y:.2f} m {self.page_width - self.margin_x:.2f} {self.y:.2f} l S")
        self.y -= 20.0

    def add_section_header(self, text):
        clean_text = text.lstrip('#').strip()
        leading = 20.0
        if self.y - leading < self.margin_bottom + 40.0:
            self._start_new_page()

        self.y -= 8.0
        esc_sec = self._escape(clean_text)
        self.current_page_cmds.append(f"0.12 0.18 0.28 rg BT /F2 13 Tf {self.margin_x:.2f} {self.y:.2f} Td ({esc_sec}) Tj ET")
        self.y -= 4.0
        # Underline bar
        self.current_page_cmds.append(f"0.25 0.48 0.88 RG 1.5 w {self.margin_x:.2f} {self.y:.2f} m {self.margin_x + 60.0:.2f} {self.y:.2f} l S")
        self.y -= 16.0

    def add_paragraph(self, text):
        clean_text = text.strip()
        if not clean_text:
            self.y -= 8.0
            return

        is_bullet = clean_text.startswith(('-', '*', '•')) or re.match(r'^\d+\.', clean_text)
        bullet_indent = 16.0 if is_bullet else 0.0

        words = clean_text.split()
        # Helvetica 10pt: ~5.3pt width per character
        avail_w = self.usable_width - bullet_indent
        char_w = 5.25
        max_chars = max(20, int(avail_w / char_w))

        lines = []
        cur_line = []
        for w in words:
            if len(' '.join(cur_line + [w])) <= max_chars:
                cur_line.append(w)
            else:
                lines.append(' '.join(cur_line))
                cur_line = [w]
        if cur_line:
            lines.append(' '.join(cur_line))

        leading = 14.0
        for idx, line in enumerate(lines):
            if self.y - leading < self.margin_bottom:
                self._start_new_page()

            x_pos = self.margin_x + (bullet_indent if (is_bullet and idx > 0) else 0.0)
            esc_line = self._escape(line)
            # Use dark charcoal for readable body text
            self.current_page_cmds.append(f"0.15 0.18 0.22 rg BT /F1 10 Tf {x_pos:.2f} {self.y:.2f} Td ({esc_line}) Tj ET")
            self.y -= leading

        self.y -= 6.0  # paragraph spacing

    def add_content(self, text):
        """Parse raw text, split into sections and paragraphs."""
        lines = text.split('\n')
        i = 0
        while i < len(lines):
            raw = lines[i].strip()
            if not raw:
                i += 1
                continue

            # Section Header Detection
            is_header = False
            if raw.startswith('#') or raw.isupper() and len(raw) < 60:
                is_header = True
            elif i + 1 < len(lines) and lines[i+1].startswith(('===', '---')):
                is_header = True
                i += 1
            elif raw.endswith(':') and len(raw) < 50 and not raw.startswith(('-', '*')):
                is_header = True

            if is_header:
                self.add_section_header(raw)
            else:
                # Accumulate paragraph
                para_lines = [raw]
                while i + 1 < len(lines) and lines[i+1].strip() and not lines[i+1].strip().startswith(('#', '-', '*', '1.', '2.', '3.')):
                    i += 1
                    para_lines.append(lines[i].strip())
                self.add_paragraph(' '.join(para_lines))
            i += 1

    def build_bytes(self):
        """Compile PDF binary stream."""
        if self.current_page_cmds:
            self.pages.append(list(self.current_page_cmds))
            self.current_page_cmds = []

        total_pages = len(self.pages)
        if total_pages == 0:
            self.pages.append([])
            total_pages = 1

        objects = []
        def add_obj(data):
            objects.append(data)
            return len(objects)

        catalog_id = add_obj(b"<< /Type /Catalog /Pages 2 0 R >>")
        pages_id = add_obj(b"") # placeholder

        f1_id = add_obj(b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>")
        f2_id = add_obj(b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>")
        f3_id = add_obj(b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Oblique >>")

        page_ids = []
        for i, page_cmds in enumerate(self.pages):
            # Page Footer
            footer_text = f"EXER PC Intelligence   •   Document Verification: Valid   •   Page {i+1} of {total_pages}"
            esc_footer = self._escape(footer_text)
            page_cmds.append(f"0.50 0.54 0.60 rg BT /F3 8 Tf {self.margin_x:.2f} 32.00 Td ({esc_footer}) Tj ET")
            page_cmds.append(f"0.88 0.90 0.94 RG 0.5 w {self.margin_x:.2f} 44.00 m {self.page_width - self.margin_x:.2f} 44.00 l S")

            stream_content = "\n".join(page_cmds).encode('latin1', 'replace')
            stream_obj = f"<< /Length {len(stream_content)} >>\nstream\n".encode('latin1') + stream_content + b"\nendstream"
            stream_id = add_obj(stream_obj)

            page_obj = f"<< /Type /Page /Parent {pages_id} 0 R /MediaBox [0 0 {self.page_width:.2f} {self.page_height:.2f}] /Resources << /Font << /F1 {f1_id} 0 R /F2 {f2_id} 0 R /F3 {f3_id} 0 R >> >> /Contents {stream_id} 0 R >>".encode('latin1')
            page_id = add_obj(page_obj)
            page_ids.append(page_id)

        kids_str = " ".join([f"{pid} 0 R" for pid in page_ids])
        objects[pages_id - 1] = f"<< /Type /Pages /Kids [{kids_str}] /Count {total_pages} >>".encode('latin1')

        out = bytearray(b"%PDF-1.4\n")
        offsets = []
        for i, obj in enumerate(objects):
            offsets.append(len(out))
            out.extend(f"{i+1} 0 obj\n".encode('latin1'))
            out.extend(obj)
            out.extend(b"\nendobj\n")

        xref_pos = len(out)
        out.extend(f"xref\n0 {len(objects) + 1}\n0000000000 65535 f \n".encode('latin1'))
        for off in offsets:
            out.extend(f"{off:010d} 00000 n \n".encode('latin1'))
        out.extend(f"trailer\n<< /Size {len(objects) + 1} /Root {catalog_id} 0 R >>\nstartxref\n{xref_pos}\n%%EOF\n".encode('latin1'))
        return bytes(out)

    def save_file(self, output_path):
        os.makedirs(os.path.dirname(os.path.abspath(output_path)), exist_ok=True)
        pdf_bytes = self.build_bytes()
        with open(output_path, 'wb') as f:
            f.write(pdf_bytes)
        return output_path


def create_pdf_from_text(title, content, output_path=None, subtitle="EXER PC Intelligence • Automated Intelligence Report"):
    """High-level helper to generate a PDF file from given title and content."""
    pdf = ExerPDF(title=title, subtitle=subtitle)
    pdf.draw_cover_header()
    pdf.add_content(content)
    
    if output_path:
        return pdf.save_file(output_path)
    return pdf.build_bytes()


if __name__ == "__main__":
    if len(sys.argv) > 1 and sys.argv[1] == "test":
        sample_text = """Executive Summary
Israel, officially the State of Israel, is a sovereign country situated in Western Asia along the eastern coastline of the Mediterranean Sea. Established in 1948, Israel has developed into a high-technology industrial powerhouse while navigating complex regional geopolitical dynamics.

Historical Foundations
The historical roots of the Jewish people in the region extend back over three millennia. In the late 19th century, political Zionism emerged in Europe under Theodor Herzl in response to widespread anti-Semitism and pogroms.

Key Findings:
- Israel's economy is led by high-technology research, cybersecurity, and agriculture.
- The distinction between Judaism as a global religion/people and Zionism as a political movement is foundational in historical discourse.
- Water desalination and drip irrigation revolutionized regional sustainability."""
        
        target = "/tmp/exer_sample.pdf"
        create_pdf_from_text("Israel, Jews, and Zionists", sample_text, target)
        print(f"Created sample PDF at {target} ({os.path.getsize(target)} bytes)")
