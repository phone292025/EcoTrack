# EcoTrack Data Dictionary

This document describes the current database tables, fields, and key relationships defined in `ecotrack.sql`.

## 1. `users`

| Field | Type | Key / Constraint | Null | Description / Relationship |
| --- | --- | --- | --- | --- |
| `user_id` | `INT` | Primary key, auto increment | No | Unique identifier for each user |
| `username` | `VARCHAR(50)` | Unique | No | Login name shown in the app |
| `email` | `VARCHAR(100)` | Unique | No | User email address |
| `password` | `VARCHAR(255)` | - | No | Hashed password |
| `role` | `ENUM('participant','moderator','admin')` | Default `participant` | No | Access role used by role-based routing |
| `points` | `INT` | Default `0` | No | Current points balance |
| `streak` | `INT` | Default `0` | No | Current streak count |
| `last_checkin` | `DATE` | Default `NULL` | Yes | Most recent check-in date |
| `avatar` | `VARCHAR(255)` | Default `NULL` | Yes | Optional avatar path |
| `created_at` | `DATETIME` | Default current timestamp | Yes | Account creation timestamp |

## 2. `categories`

| Field | Type | Key / Constraint | Null | Description / Relationship |
| --- | --- | --- | --- | --- |
| `cat_id` | `INT` | Primary key, auto increment | No | Unique identifier for activity category |
| `name` | `VARCHAR(50)` | - | No | Category name such as Recycling or Energy Saving |
| `icon` | `VARCHAR(100)` | Default `NULL` | Yes | Optional icon filename |
| `co2_per_point` | `DECIMAL(6,4)` | Default `0.0100` | Yes | CO2 factor used in impact calculations |

## 3. `activity_logs`

| Field | Type | Key / Constraint | Null | Description / Relationship |
| --- | --- | --- | --- | --- |
| `log_id` | `INT` | Primary key, auto increment | No | Unique identifier for an activity submission |
| `user_id` | `INT` | Foreign key -> `users.user_id` | No | Participant who submitted the log |
| `cat_id` | `INT` | Foreign key -> `categories.cat_id` | No | Category selected for the log |
| `description` | `TEXT` | - | Yes | User explanation of the activity |
| `evidence` | `VARCHAR(255)` | Default `NULL` | Yes | Uploaded evidence file path |
| `points` | `INT` | Default `0` | No | Points associated with the log after review |
| `status` | `ENUM('pending','approved','rejected','flagged')` | Default `pending` | Yes | Review state |
| `flagged_by` | `INT` | Foreign key -> `users.user_id` | Yes | Moderator or admin who flagged the submission |
| `reviewed_by` | `INT` | Foreign key -> `users.user_id` | Yes | Moderator or admin who approved or rejected the log |
| `created_at` | `DATETIME` | Default current timestamp | Yes | Submission timestamp |
| `reviewed_at` | `DATETIME` | Default `NULL` | Yes | Review completion timestamp |

## 4. `challenges`

| Field | Type | Key / Constraint | Null | Description / Relationship |
| --- | --- | --- | --- | --- |
| `challenge_id` | `INT` | Primary key, auto increment | No | Unique challenge identifier |
| `title` | `VARCHAR(150)` | - | No | Challenge title |
| `description` | `TEXT` | - | Yes | Challenge details and instructions |
| `cat_id` | `INT` | Foreign key -> `categories.cat_id` | Yes | Optional linked category |
| `difficulty` | `ENUM('easy','medium','hard')` | Default `easy` | Yes | Challenge difficulty tier |
| `points` | `INT` | Default `10` | No | Points awarded on completion |
| `start_date` | `DATE` | Default `NULL` | Yes | Challenge start date |
| `end_date` | `DATE` | Default `NULL` | Yes | Challenge end date |
| `created_by` | `INT` | Foreign key -> `users.user_id` | Yes | Moderator or admin who created the challenge |
| `status` | `ENUM('draft','active','closed')` | Default `draft` | Yes | Publish state |
| `created_at` | `DATETIME` | Default current timestamp | Yes | Creation timestamp |

## 5. `challenge_participants`

| Field | Type | Key / Constraint | Null | Description / Relationship |
| --- | --- | --- | --- | --- |
| `id` | `INT` | Primary key, auto increment | No | Unique join record identifier |
| `challenge_id` | `INT` | Foreign key -> `challenges.challenge_id`, unique pair with `user_id` | No | Joined challenge |
| `user_id` | `INT` | Foreign key -> `users.user_id`, unique pair with `challenge_id` | No | Participant who joined |
| `joined_at` | `DATETIME` | Default current timestamp | Yes | Join timestamp |
| `completed` | `TINYINT(1)` | Default `0` | Yes | Completion flag |
| `completed_at` | `DATETIME` | Default `NULL` | Yes | Completion timestamp |

## 6. `badges`

| Field | Type | Key / Constraint | Null | Description / Relationship |
| --- | --- | --- | --- | --- |
| `badge_id` | `INT` | Primary key, auto increment | No | Unique badge identifier |
| `name` | `VARCHAR(100)` | - | No | Badge title |
| `description` | `TEXT` | - | Yes | Badge description shown in the gallery |
| `icon` | `VARCHAR(255)` | Default `NULL` | Yes | Badge image or icon filename |
| `criteria` | `VARCHAR(255)` | Default `NULL` | Yes | Business rule string such as `points>=50` |
| `created_by` | `INT` | Foreign key -> `users.user_id` | Yes | Admin creator reference |

## 7. `user_badges`

| Field | Type | Key / Constraint | Null | Description / Relationship |
| --- | --- | --- | --- | --- |
| `id` | `INT` | Primary key, auto increment | No | Unique earned-badge record |
| `user_id` | `INT` | Foreign key -> `users.user_id`, unique pair with `badge_id` | No | Badge owner |
| `badge_id` | `INT` | Foreign key -> `badges.badge_id`, unique pair with `user_id` | No | Badge that was earned |
| `earned_at` | `DATETIME` | Default current timestamp | Yes | When the badge was granted |

## 8. `goals`

| Field | Type | Key / Constraint | Null | Description / Relationship |
| --- | --- | --- | --- | --- |
| `goal_id` | `INT` | Primary key, auto increment | No | Unique goal identifier |
| `user_id` | `INT` | Foreign key -> `users.user_id` | No | Participant who owns the goal |
| `target` | `INT` | - | No | Goal target points |
| `period` | `ENUM('weekly','monthly')` | Default `weekly` | Yes | Goal period type |
| `start_date` | `DATE` | - | No | Goal start date |
| `end_date` | `DATE` | - | No | Goal end date |
| `bonus_awarded` | `TINYINT(1)` | Default `0` | Yes | Indicates whether a goal bonus has been awarded |
| `created_at` | `DATETIME` | Default current timestamp | Yes | Goal creation timestamp |

## 9. `rewards`

| Field | Type | Key / Constraint | Null | Description / Relationship |
| --- | --- | --- | --- | --- |
| `reward_id` | `INT` | Primary key, auto increment | No | Unique reward identifier |
| `name` | `VARCHAR(150)` | - | No | Reward title shown in the shop |
| `description` | `TEXT` | - | Yes | Reward description |
| `image` | `VARCHAR(255)` | Default `NULL` | Yes | Optional reward image path |
| `category` | `ENUM('Lifestyle','Campus','Eco Essentials')` | Default `Lifestyle` | Yes | Shop category |
| `point_cost` | `INT` | Default `50` | No | Cost to redeem the reward |
| `stock` | `INT` | Default `0` | No | Remaining reward inventory |
| `active` | `TINYINT(1)` | Default `1` | Yes | Visibility or sale status |
| `created_at` | `DATETIME` | Default current timestamp | Yes | Reward creation timestamp |

## 10. `redemptions`

| Field | Type | Key / Constraint | Null | Description / Relationship |
| --- | --- | --- | --- | --- |
| `redemption_id` | `INT` | Primary key, auto increment | No | Unique redemption identifier |
| `user_id` | `INT` | Foreign key -> `users.user_id` | No | Participant who redeemed the reward |
| `reward_id` | `INT` | Foreign key -> `rewards.reward_id` | No | Redeemed reward |
| `points_spent` | `INT` | - | No | Points deducted for this redemption |
| `redeemed_at` | `DATETIME` | Default current timestamp | Yes | Redemption timestamp |

## 11. `points_transactions`

| Field | Type | Key / Constraint | Null | Description / Relationship |
| --- | --- | --- | --- | --- |
| `txn_id` | `INT` | Primary key, auto increment | No | Unique ledger entry identifier |
| `user_id` | `INT` | Foreign key -> `users.user_id` | No | User whose balance changed |
| `delta` | `INT` | - | No | Positive or negative points change |
| `reason` | `VARCHAR(255)` | Default `NULL` | Yes | Explanation for the points movement |
| `ref_id` | `INT` | Default `NULL` | Yes | Optional reference to the business event that caused the change |
| `created_at` | `DATETIME` | Default current timestamp | Yes | Ledger timestamp |

## 12. `daily_checkins`

| Field | Type | Key / Constraint | Null | Description / Relationship |
| --- | --- | --- | --- | --- |
| `checkin_id` | `INT` | Primary key, auto increment | No | Unique daily check-in record |
| `user_id` | `INT` | Foreign key -> `users.user_id`, unique pair with `checkin_date` | No | User who checked in |
| `checkin_date` | `DATE` | Unique pair with `user_id` | No | Date of the check-in |

## 13. `eco_tips`

| Field | Type | Key / Constraint | Null | Description / Relationship |
| --- | --- | --- | --- | --- |
| `tip_id` | `INT` | Primary key, auto increment | No | Unique eco tip identifier |
| `title` | `VARCHAR(200)` | - | No | Eco tip title |
| `body` | `TEXT` | - | Yes | Eco tip content |
| `created_by` | `INT` | Foreign key -> `users.user_id` | Yes | Moderator or admin who posted the tip |
| `created_at` | `DATETIME` | Default current timestamp | Yes | Tip creation timestamp |

## 14. `announcements`

| Field | Type | Key / Constraint | Null | Description / Relationship |
| --- | --- | --- | --- | --- |
| `ann_id` | `INT` | Primary key, auto increment | No | Unique announcement identifier |
| `title` | `VARCHAR(200)` | - | No | Announcement title |
| `body` | `TEXT` | - | Yes | Announcement content |
| `created_by` | `INT` | Foreign key -> `users.user_id` | Yes | Admin who posted the announcement |
| `created_at` | `DATETIME` | Default current timestamp | Yes | Announcement creation timestamp |

## Relationship Summary

- One user can create many activity logs, goals, points transactions, redemptions, check-ins, and earned badges.
- One category can classify many activity logs and can optionally group many challenges.
- One challenge can have many participant join records.
- One reward can be redeemed many times until stock is exhausted.
- One badge can be earned by many users through `user_badges`.
- One moderator or admin can create many challenges, eco tips, announcements, and badge definitions.
