# EcoTrack Wireframes

These are low-fidelity structural wireframes for the main current pages of the EcoTrack web application. They focus on layout, grouping, and major actions rather than final visual styling.

## 1. Landing / Home

```text
+---------------------------------------------------------------+
| Top Navigation: Logo | Login | Register                       |
+---------------------------------------------------------------+
| Hero Section                                                 |
| - Headline                                                   |
| - Short sustainability message                               |
| - Main CTA buttons                                           |
+---------------------------------------------------------------+
| Feature Highlights                                           |
| [Track activities] [Join challenges] [Redeem rewards]        |
+---------------------------------------------------------------+
| Footer                                                       |
+---------------------------------------------------------------+
```

## 2. Login

```text
+-------------------------------------------+
| Logo / Page Title                         |
+-------------------------------------------+
| Login Card                                |
| - Username or Email input                 |
| - Password input                          |
| - Submit button                           |
| - Link to Register                        |
+-------------------------------------------+
```

## 3. Register

```text
+--------------------------------------------------+
| Logo / Page Title                                |
+--------------------------------------------------+
| Registration Card                                |
| - Username                                       |
| - Email                                          |
| - Password                                       |
| - Confirm Password                               |
| - Register button                                |
| - Link to Login                                  |
+--------------------------------------------------+
```

## 4. Participant Dashboard

```text
+-----------------------------------------------------------------------+
| Header / Role Navigation                                              |
+-----------------------------------------------------------------------+
| Page Heading + Intro + Points Badge                                   |
+-----------------------------------------------------------------------+
| Stats Row                                                             |
| [Current Points] [Current Streak] [Joined Challenges] [Completed]     |
+-----------------------------------------------------------------------+
| Main Left Column                          | Right Column              |
| - Daily Check-In Card                     | - Goal Card               |
| - Recent Activity Card                    | - Eco Impact Summary      |
| - Announcements                           | - Eco Tips                |
+-----------------------------------------------------------------------+
```

## 5. Participant Log Activity

```text
+-----------------------------------------------------------------------+
| Header / Role Navigation                                              |
+-----------------------------------------------------------------------+
| Page Heading + Intro                                                  |
+-----------------------------------------------------------------------+
| Log Activity Form Card                                                |
| - Category select                                                     |
| - Description textarea                                                |
| - Evidence upload                                                     |
| - Live image preview                                                  |
| - Submit button                                                       |
+-----------------------------------------------------------------------+
| Recent Guidance / Tips Card                                           |
+-----------------------------------------------------------------------+
```

## 6. Participant Points Dashboard

```text
+-----------------------------------------------------------------------+
| Header / Role Navigation                                              |
+-----------------------------------------------------------------------+
| Page Heading + Intro + Points Badge                                   |
+-----------------------------------------------------------------------+
| Summary Cards                                                         |
| [Now] [Earned] [Spent]                                                |
+-----------------------------------------------------------------------+
| Left Column                                  | Right Column           |
| - Points History Table                       | - Category Breakdown   |
| - Quick Note Card                            | - Chart / Empty State  |
+-----------------------------------------------------------------------+
```

## 7. Participant Profile

```text
+-----------------------------------------------------------------------+
| Header / Role Navigation                                              |
+-----------------------------------------------------------------------+
| Page Heading + Intro + Points Badge                                   |
+-----------------------------------------------------------------------+
| Top Summary Cards                                                     |
| [User] [Streak] [Done]                                                |
+-----------------------------------------------------------------------+
| Main Content                                                          |
| - Carbon Footprint Graph                                              |
| - Activity History Table                                              |
| - Impact Summary / Goal Progress                                      |
+-----------------------------------------------------------------------+
| Bottom Full-Width Section                                             |
| - Badge Gallery Grid                                                  |
+-----------------------------------------------------------------------+
```

## 8. Participant Challenges

```text
+-----------------------------------------------------------------------+
| Header / Role Navigation                                              |
+-----------------------------------------------------------------------+
| Page Heading + Intro                                                  |
+-----------------------------------------------------------------------+
| Challenge Filters / Status Summary                                    |
+-----------------------------------------------------------------------+
| Challenge Grid                                                        |
| [Challenge Card] [Challenge Card] [Challenge Card]                    |
| Each card: title, category, difficulty, points, dates, action button  |
+-----------------------------------------------------------------------+
```

## 9. Participant Green Shop

```text
+-----------------------------------------------------------------------+
| Header / Role Navigation                                              |
+-----------------------------------------------------------------------+
| Shop Hero + Points Balance                                            |
+-----------------------------------------------------------------------+
| Search + Category Filter                                              |
+-----------------------------------------------------------------------+
| Reward Grid                                                           |
| [Reward Card] [Reward Card] [Reward Card]                             |
| Each card: image, name, category, stock, point cost, redeem action    |
+-----------------------------------------------------------------------+
```

## 10. Moderator Review Submissions

```text
+-----------------------------------------------------------------------+
| Header / Moderator Navigation                                         |
+-----------------------------------------------------------------------+
| Page Heading + Review Summary                                         |
+-----------------------------------------------------------------------+
| Pending Review List                                                   |
| [Submission Card / Row]                                               |
| - Participant info                                                    |
| - Category, description, evidence preview                             |
| - Approve / Reject / Flag actions                                     |
+-----------------------------------------------------------------------+
| Admin-only Flagged Queue (when admin views same review page)          |
+-----------------------------------------------------------------------+
```

## 11. Moderator Challenge Management

```text
+-----------------------------------------------------------------------+
| Header / Moderator Navigation                                         |
+-----------------------------------------------------------------------+
| Page Heading + Intro                                                  |
+-----------------------------------------------------------------------+
| Create Challenge Form                                                 |
| - Title                                                               |
| - Description                                                         |
| - Category                                                            |
| - Difficulty                                                          |
| - Points                                                              |
| - Start / End Date                                                    |
| - Status                                                              |
| - Save button                                                         |
+-----------------------------------------------------------------------+
| Existing Challenge Cards                                              |
| - Challenge details                                                   |
| - Joined count                                                        |
| - Status controls                                                     |
+-----------------------------------------------------------------------+
```

## 12. Admin Dashboard

```text
+-----------------------------------------------------------------------+
| Header / Admin Navigation                                             |
+-----------------------------------------------------------------------+
| Page Heading + Intro                                                  |
+-----------------------------------------------------------------------+
| Statistics Cards                                                      |
| [Users] [Pending Reviews] [Flagged] [Completion Rate]                 |
+-----------------------------------------------------------------------+
| Main Left Column                          | Right Column              |
| - Quick Management Links                  | - Challenge Snapshot      |
| - Recent Announcements                    | - Secondary Metrics       |
+-----------------------------------------------------------------------+
```

## 13. Admin User Management

```text
+-----------------------------------------------------------------------+
| Header / Admin Navigation                                             |
+-----------------------------------------------------------------------+
| Page Heading + Intro                                                  |
+-----------------------------------------------------------------------+
| Create / Edit User Form                                               |
+-----------------------------------------------------------------------+
| Role-Based Tables                                                     |
| - Admin table                                                         |
| - Moderator table                                                     |
| - Participant table                                                   |
| Each row: user info, role controls, update/delete actions             |
+-----------------------------------------------------------------------+
```

## 14. Admin Rewards Management

```text
+-----------------------------------------------------------------------+
| Header / Admin Navigation                                             |
+-----------------------------------------------------------------------+
| Page Heading + Intro                                                  |
+-----------------------------------------------------------------------+
| Left Column                                  | Right Column           |
| - Add Reward Form                            | - Reward Card Grid     |
| - Name, description, category                | - Card preview style   |
| - Cost, stock, active state                  | - Edit / delete action |
+-----------------------------------------------------------------------+
```

## 15. Admin Challenge Management

```text
+-----------------------------------------------------------------------+
| Header / Admin Navigation                                             |
+-----------------------------------------------------------------------+
| Page Heading + Intro                                                  |
+-----------------------------------------------------------------------+
| Challenge Creation Form                                               |
+-----------------------------------------------------------------------+
| Challenge Management Cards                                            |
| - Title, description, category, difficulty                            |
| - Points, dates, status                                               |
| - Edit, publish, close, delete                                        |
+-----------------------------------------------------------------------+
```

## Layout Notes

- All protected pages share the same top navigation pattern, with links changing by user role.
- Most participant pages use a dashboard-style structure with summary cards first, then detailed content.
- Admin and moderator management pages use action-first layouts: create form at the top or left, existing records below or beside it.
- On mobile, the implemented app collapses multi-column layouts into stacked cards and full-width sections.
