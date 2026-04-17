# EcoTrack Entity Relationship Diagram

The diagram below reflects the current database structure defined in `ecotrack.sql`.

```mermaid
erDiagram
    USERS {
        INT user_id PK
        VARCHAR username
        VARCHAR email
        VARCHAR password
        STRING role
        INT points
        INT streak
        DATE last_checkin
        VARCHAR avatar
        DATETIME created_at
    }

    CATEGORIES {
        INT cat_id PK
        VARCHAR name
        VARCHAR icon
        DECIMAL co2_per_point
    }

    ACTIVITY_LOGS {
        INT log_id PK
        INT user_id FK
        INT cat_id FK
        TEXT description
        VARCHAR evidence
        INT points
        STRING status
        INT flagged_by FK
        INT reviewed_by FK
        DATETIME created_at
        DATETIME reviewed_at
    }

    CHALLENGES {
        INT challenge_id PK
        VARCHAR title
        TEXT description
        INT cat_id FK
        STRING difficulty
        INT points
        DATE start_date
        DATE end_date
        INT created_by FK
        STRING status
        DATETIME created_at
    }

    CHALLENGE_PARTICIPANTS {
        INT id PK
        INT challenge_id FK
        INT user_id FK
        DATETIME joined_at
        BOOLEAN completed
        DATETIME completed_at
    }

    BADGES {
        INT badge_id PK
        VARCHAR name
        TEXT description
        VARCHAR icon
        VARCHAR criteria
        INT created_by FK
    }

    USER_BADGES {
        INT id PK
        INT user_id FK
        INT badge_id FK
        DATETIME earned_at
    }

    GOALS {
        INT goal_id PK
        INT user_id FK
        INT target
        STRING period
        DATE start_date
        DATE end_date
        BOOLEAN bonus_awarded
        DATETIME created_at
    }

    REWARDS {
        INT reward_id PK
        VARCHAR name
        TEXT description
        VARCHAR image
        STRING category
        INT point_cost
        INT stock
        BOOLEAN active
        DATETIME created_at
    }

    REDEMPTIONS {
        INT redemption_id PK
        INT user_id FK
        INT reward_id FK
        INT points_spent
        DATETIME redeemed_at
    }

    POINTS_TRANSACTIONS {
        INT txn_id PK
        INT user_id FK
        INT delta
        VARCHAR reason
        INT ref_id
        DATETIME created_at
    }

    DAILY_CHECKINS {
        INT checkin_id PK
        INT user_id FK
        DATE checkin_date
    }

    ECO_TIPS {
        INT tip_id PK
        VARCHAR title
        TEXT body
        INT created_by FK
        DATETIME created_at
    }

    ANNOUNCEMENTS {
        INT ann_id PK
        VARCHAR title
        TEXT body
        INT created_by FK
        DATETIME created_at
    }

    USERS ||--o{ ACTIVITY_LOGS : submits
    CATEGORIES ||--o{ ACTIVITY_LOGS : classifies
    USERS o|--o{ ACTIVITY_LOGS : flags
    USERS o|--o{ ACTIVITY_LOGS : reviews

    CATEGORIES o|--o{ CHALLENGES : groups
    USERS o|--o{ CHALLENGES : creates

    CHALLENGES ||--o{ CHALLENGE_PARTICIPANTS : includes
    USERS ||--o{ CHALLENGE_PARTICIPANTS : joins

    USERS o|--o{ BADGES : configures
    USERS ||--o{ USER_BADGES : earns
    BADGES ||--o{ USER_BADGES : awards

    USERS ||--o{ GOALS : sets

    USERS ||--o{ REDEMPTIONS : makes
    REWARDS ||--o{ REDEMPTIONS : redeems

    USERS ||--o{ POINTS_TRANSACTIONS : owns
    USERS ||--o{ DAILY_CHECKINS : checks_in

    USERS o|--o{ ECO_TIPS : posts
    USERS o|--o{ ANNOUNCEMENTS : posts
```

## Relationship Notes

- `activity_logs.flagged_by` and `activity_logs.reviewed_by` both reference `users.user_id`.
- `challenge_participants` stores each join record and whether the challenge has been completed.
- `user_badges` resolves the many-to-many relationship between users and badges.
- `points_transactions.ref_id` is a reference marker used by the app logic. It is not enforced as a database foreign key because it may point to different business events.
- `challenges.cat_id` is optional, so a challenge can exist without a category, although the current challenge forms encourage category-based challenges.
