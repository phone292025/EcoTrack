# EcoTrack Flowchart

This document summarizes the main live system processes in the current EcoTrack application.

## 1. Authentication And Role Redirect

```mermaid
flowchart TD
    A["User opens login page"] --> B["Submit username or email and password"]
    B --> C{"Credentials valid?"}
    C -- "No" --> D["Show login error"]
    C -- "Yes" --> E["Create session: user_id, username, role"]
    E --> F{"Role"}
    F -- "participant" --> G["Redirect to participant dashboard"]
    F -- "moderator" --> H["Redirect to moderator dashboard"]
    F -- "admin" --> I["Redirect to admin dashboard"]
    G --> J["Protected participant pages require participant role"]
    H --> K["Protected moderator pages require moderator or admin role where allowed"]
    I --> L["Protected admin pages require admin role"]
```

## 2. Participant Activity Submission And Review

```mermaid
flowchart TD
    A["Participant opens Log Activity"] --> B["Select category, enter description, choose optional evidence image"]
    B --> C["Submit activity log"]
    C --> D{"Validation passes?"}
    D -- "No" --> E["Return form errors and keep entered values"]
    D -- "Yes" --> F["Save activity_logs row with pending status"]
    F --> G["Participant sees success message after redirect"]
    G --> H["Moderator opens Review Submissions"]
    H --> I{"Submission decision"}
    I -- "Approve" --> J["Set status to approved"]
    J --> K["Award participant points"]
    K --> L["Insert points_transactions row"]
    L --> M["Refresh streaks, badges, and challenge progress"]
    I -- "Reject" --> N["Set status to rejected"]
    I -- "Flag" --> O["Set status to flagged and store flagged_by"]
    O --> P["Admin reviews flagged queue"]
    P --> Q{"Admin resolution"}
    Q -- "Approve" --> J
    Q -- "Reject" --> N
```

## 3. Challenge Creation, Participation, And Completion

```mermaid
flowchart TD
    A["Moderator or admin opens challenge management page"] --> B["Create challenge with title, description, category, difficulty, points, and dates"]
    B --> C["Save challenge as draft or active"]
    C --> D["Participant opens Challenges page"]
    D --> E["Browse active challenges"]
    E --> F["Join selected challenge"]
    F --> G["Insert challenge_participants row"]
    G --> H["Participant logs eco activity"]
    H --> I["Moderator or admin approves activity"]
    I --> J["System refreshes challenge progress"]
    J --> K{"Approved activity matches joined challenge category and date?"}
    K -- "No" --> L["Challenge remains joined only"]
    K -- "Yes" --> M["Mark challenge_participants.completed = 1"]
    M --> N["Award challenge points to participant"]
    N --> O["Insert points transaction and update totals"]
```

## 4. Reward Redemption And Points Deduction

```mermaid
flowchart TD
    A["Admin creates or updates reward catalogue"] --> B["Participant opens Green Shop"]
    B --> C["Search or filter rewards by category"]
    C --> D["Click redeem on active reward"]
    D --> E{"Enough points and stock available?"}
    E -- "No" --> F["Show disabled or failed redemption state"]
    E -- "Yes" --> G["Start transaction"]
    G --> H["Lock current user row and read points balance"]
    H --> I["Insert redemption record"]
    I --> J["Decrease reward stock"]
    J --> K["Deduct points from user balance"]
    K --> L["Insert negative points_transactions row"]
    L --> M["Commit transaction"]
    M --> N["Show updated balance and redemption success"]
```
