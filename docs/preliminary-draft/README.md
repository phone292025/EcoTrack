# EcoTrack Preliminary Draft

This folder contains the preliminary draft documents for the current EcoTrack web application.

## Purpose

These documents are submission-ready planning artifacts for the system as it exists in the current codebase. They are written in Markdown and Mermaid so they can be viewed directly in the repository without needing external design software.

## Current-State Baseline

This draft reflects the implemented EcoTrack app in:

- `ecotrack.sql` for the database schema
- `includes/header.php` and `includes/auth.php` for navigation and role routing
- the current `participant`, `moderator`, and `admin` modules for page structure and system flows
- `includes/functions.php` for shared business logic such as points, streaks, check-ins, challenge progress, and reward redemption

It does not try to force the documentation to match an older proposal if the live system currently behaves differently.

## Deliverables

- [Flowchart](./flowchart.md)
- [Entity Relationship Diagram](./erd.md)
- [Data Dictionary](./data-dictionary.md)
- [Wireframes](./wireframes.md)
- [Navigation Map](./navigation-map.md)

## Scope Note

The draft is based on the current implemented EcoTrack system, including:

- guest authentication pages
- participant dashboard, logging, challenges, shop, points, leaderboard, and profile pages
- moderator review, challenge management, and eco tips pages
- admin dashboard and management pages for users, challenges, rewards, badges, and announcements

## Format Decisions

- Output format: Markdown + Mermaid only
- Storage location: repository documentation folder
- Wireframes: low-fidelity structural wireframes
- Figma: optional later, not required for this draft

## Source Files Used

- `ecotrack.sql`
- `includes/header.php`
- `includes/auth.php`
- `includes/functions.php`
- `index.php`
- `login.php`
- `register.php`
- `participant/*.php`
- `moderator/*.php`
- `admin/*.php`

## Review Checklist

Before submitting, verify that:

- every route in the navigation map exists in the current app
- every table and column in the ERD and data dictionary matches `ecotrack.sql`
- every flowchart process is backed by a real implemented page or function
- every wireframe matches the current layout structure of the page it represents
- Mermaid blocks render correctly in GitHub-compatible Markdown viewers
