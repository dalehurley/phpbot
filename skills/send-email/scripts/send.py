#!/usr/bin/env python3
"""
Send email via SMTP.
Uses Python stdlib only (smtplib, email) -- no external dependencies.

Credentials via environment variables:
    SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS, EMAIL_FROM

Usage:
    send.py --to <addr> --subject <subj> --body <body> [--attachment path] [--html] [--cc addr]
"""

import sys
import os
import argparse
import smtplib
import mimetypes
from email.mime.multipart import MIMEMultipart
from email.mime.text import MIMEText
from email.mime.base import MIMEBase
from email import encoders


def get_env(key, required=True, default=None):
    """Get environment variable with validation."""
    value = os.environ.get(key, default)
    if required and not value:
        print(
            f"Error: {key} is required. "
            f"Use get_keys to retrieve credentials from the key store.",
            file=sys.stderr,
        )
        sys.exit(1)
    return value


def send_email(smtp_host, smtp_port, smtp_user, smtp_pass, email_from,
               to_addrs, cc_addrs, subject, body, is_html=False, attachment_path=None):
    """Send an email via SMTP."""

    # Build the message
    msg = MIMEMultipart()
    msg["From"] = email_from
    msg["To"] = ", ".join(to_addrs)
    if cc_addrs:
        msg["Cc"] = ", ".join(cc_addrs)
    msg["Subject"] = subject

    # Body
    content_type = "html" if is_html else "plain"
    msg.attach(MIMEText(body, content_type, "utf-8"))

    # Attachment
    if attachment_path:
        if not os.path.exists(attachment_path):
            print(f"Error: Attachment not found: {attachment_path}", file=sys.stderr)
            sys.exit(1)

        mime_type, _ = mimetypes.guess_type(attachment_path)
        if mime_type is None:
            mime_type = "application/octet-stream"
        main_type, sub_type = mime_type.split("/", 1)

        with open(attachment_path, "rb") as f:
            attachment = MIMEBase(main_type, sub_type)
            attachment.set_payload(f.read())
            encoders.encode_base64(attachment)
            attachment.add_header(
                "Content-Disposition",
                "attachment",
                filename=os.path.basename(attachment_path),
            )
            msg.attach(attachment)

    # All recipients
    all_recipients = to_addrs + (cc_addrs or [])

    # Send
    port = int(smtp_port)
    if port == 465:
        # SSL
        with smtplib.SMTP_SSL(smtp_host, port, timeout=30) as server:
            server.login(smtp_user, smtp_pass)
            server.sendmail(email_from, all_recipients, msg.as_string())
    else:
        # STARTTLS (port 587 or other)
        with smtplib.SMTP(smtp_host, port, timeout=30) as server:
            server.ehlo()
            server.starttls()
            server.ehlo()
            server.login(smtp_user, smtp_pass)
            server.sendmail(email_from, all_recipients, msg.as_string())

    return True


def main():
    parser = argparse.ArgumentParser(description="Send email via SMTP")
    parser.add_argument("--to", required=True, help="Recipient email(s), comma-separated")
    parser.add_argument("--subject", "-s", required=True, help="Email subject")
    parser.add_argument("--body", "-b", required=True, help="Email body")
    parser.add_argument("--attachment", "-a", help="File path to attach")
    parser.add_argument("--html", action="store_true", help="Send body as HTML")
    parser.add_argument("--cc", help="CC recipients, comma-separated")

    args = parser.parse_args()

    # Get credentials from environment
    smtp_host = get_env("SMTP_HOST")
    smtp_port = get_env("SMTP_PORT", default="587")
    smtp_user = get_env("SMTP_USER")
    smtp_pass = get_env("SMTP_PASS")
    email_from = get_env("EMAIL_FROM", required=False, default=smtp_user)

    # Parse recipients
    to_addrs = [addr.strip() for addr in args.to.split(",") if addr.strip()]
    cc_addrs = [addr.strip() for addr in args.cc.split(",") if addr.strip()] if args.cc else []

    # Resolve attachment path
    attachment_path = None
    if args.attachment:
        attachment_path = os.path.expanduser(args.attachment)
        attachment_path = os.path.abspath(attachment_path)

    try:
        send_email(
            smtp_host, smtp_port, smtp_user, smtp_pass, email_from,
            to_addrs, cc_addrs, args.subject, args.body,
            is_html=args.html, attachment_path=attachment_path,
        )
        print(f"Email sent successfully to: {', '.join(to_addrs)}")
        if cc_addrs:
            print(f"CC: {', '.join(cc_addrs)}")
        print(f"Subject: {args.subject}")
        if attachment_path:
            print(f"Attachment: {os.path.basename(attachment_path)}")
    except smtplib.SMTPAuthenticationError:
        print("Error: SMTP authentication failed. Check SMTP_USER and SMTP_PASS.", file=sys.stderr)
        sys.exit(1)
    except smtplib.SMTPException as e:
        print(f"Error sending email: {e}", file=sys.stderr)
        sys.exit(1)
    except Exception as e:
        print(f"Error: {e}", file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()
