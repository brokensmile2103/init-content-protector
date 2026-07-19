# Init Content Protector – Anti-Copy, Anti-Scrape, Encrypt-All

> Protect your content from copying, scraping, and inspection using JS blocking, keyword cloaking, noise injection, and optional full encryption.

**No bloat. Just clean protection. Built for themes and developers.**

[![Version](https://img.shields.io/badge/stable-v1.4-blue.svg)](https://wordpress.org/plugins/init-content-protector/)
[![License](https://img.shields.io/badge/license-GPLv2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
![Made with ❤️ in HCMC](https://img.shields.io/badge/Made%20with-%E2%9D%A4%EF%B8%8F%20in%20HCMC-blue)

## Overview

Init Content Protector is a powerful yet lightweight plugin that safeguards your post content from unauthorized copying, scraping tools, and inspection via browser developer tools.

Its goal is to deter casual bots and crawlers, not to stop a determined human — anyone who can see content can always screenshot or retype it. What this plugin does is raise the cost of automated scraping, cleanly and without bloating your database or breaking your theme.

Use this plugin to harden your site's content visibility while maintaining a smooth reading experience for real users.

## Features

- **JavaScript-based copy protection** — blocks selection, right-click, print, and DevTools shortcuts
- **Full content encryption** (AES-256) with client-side decryption via CryptoJS
  - **Inline delivery** (default): simple, works everywhere
  - **Enhanced delivery**: fetches the decryption key via a REST API endpoint after page load, keeping it out of cached/static HTML and playing nicely with full-page cache plugins
  - Encrypted output is cached per post (invalidated automatically on edit) to avoid redundant server-side crypto work on every page view
  - Fails open gracefully on hosts missing OpenSSL/PBKDF2 support, instead of breaking the page
- **Keyword cloaking** using CSS pseudo-elements
- **Invisible noise injection** to confuse crawlers
  - Only ever injected into plain text — never inside HTML tags, and never inside `<script>`, `<style>`, `<pre>`, `<textarea>`, `<code>`, `<select>`, `<option>`, `<title>`, or `<svg>`
  - Configurable injection rate (1–50%, default 7%)
- **AMP-aware** — automatically skips protection on AMP endpoints instead of producing invalid markup
- **Per-post-type configuration** and **excluded user roles** (protection never applies to selected roles)
- **Custom encryption key** per site
- **Custom content selector**, with an **Auto-detect** button in settings that tests common theme/builder selectors against your latest post
- Vanilla JS + REST API architecture — no jQuery, no external dependencies beyond CryptoJS

## Installation

1. Upload to `/wp-content/plugins/init-content-protector`
2. Activate in WordPress admin
3. Go to **Settings → Init Content Protector** and configure your preferred options

## A Note on What This Plugin Can (and Can't) Do

This plugin raises the difficulty of automated scraping — it does not, and cannot, make content theft impossible. A visitor who can read your content can always screenshot it, photograph the screen, or type it out by hand. Init Content Protector is built around that reality: every feature is designed to slow down bots and crawlers without adding unnecessary risk (like unbounded database growth) in the process of chasing unattainable "perfect" protection.

## License

GPLv2 or later — open source, minimal, developer-first.

## Part of Init Plugin Suite

Init Content Protector is part of the [Init Plugin Suite](https://en.inithtml.com/init-plugin-suite-minimalist-powerful-and-free-wordpress-plugins/) — a collection of blazing-fast, no-bloat plugins made for WordPress developers who care about quality and speed.
