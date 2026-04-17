# EcoTrack Navigation Map

This site map reflects the current role-based navigation structure of the EcoTrack web application.

```mermaid
flowchart TD
    A["Guest user"] --> B["Home / index.php"]
    A --> C["Login / login.php"]
    A --> D["Register / register.php"]

    C --> E{"Authenticated?"}
    E -- "No" --> C
    E -- "Yes" --> F{"Role redirect"}

    F -- "participant" --> G["Participant Dashboard"]
    F -- "moderator" --> H["Moderator Dashboard"]
    F -- "admin" --> I["Admin Dashboard"]

    G --> G1["Log Activity"]
    G --> G2["Challenges"]
    G --> G3["Green Shop"]
    G --> G4["Points"]
    G --> G5["Leaderboard"]
    G --> G6["Profile"]

    H --> H1["Review Submissions"]
    H --> H2["Challenge Management"]
    H --> H3["Eco Tips"]

    I --> I1["User Management"]
    I --> I2["Challenge Management"]
    I --> I3["Eco Tips"]
    I --> I4["Rewards Management"]
    I --> I5["Badges Management"]
    I --> I6["Announcements"]
    I --> H1

    G1 --> G
    G2 --> G
    G3 --> G
    G4 --> G
    G5 --> G
    G6 --> G

    H1 --> H
    H2 --> H
    H3 --> H

    I1 --> I
    I2 --> I
    I3 --> I
    I4 --> I
    I5 --> I
    I6 --> I

    G --> Z["Logout"]
    H --> Z
    I --> Z
    Z --> C
```

## Guest Navigation

- Home
- Login
- Register

## Participant Navigation

- Dashboard
- Log Activity
- Challenges
- Green Shop
- Points
- Leaderboard
- Profile
- Logout

## Moderator Navigation

- Dashboard
- Review Submissions
- Challenges
- Eco Tips
- Logout

## Admin Navigation

- Dashboard
- Users
- Challenges
- Eco Tips
- Rewards
- Badges
- Announcements
- Review Submissions through moderator review workflow where permitted
- Logout

## Access Rules

- Guests can only access public pages.
- Participants are redirected to the participant dashboard after login.
- Moderators are redirected to the moderator dashboard after login.
- Admins are redirected to the admin dashboard after login.
- Protected pages use role checks from `includes/auth.php`.
