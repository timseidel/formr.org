# Plan for building new features into the formr API

## Overview

The goal is to expand the current `formr` API to support more comprehensive management of studies (runs), sessions, and data. Currently, the API is limited to retrieving results, creating sessions, and ending external units. The proposed plan introduces a RESTful structure with granular scopes to allow secure access to different parts of the system.

## Proposed Features

The features are organized by resource modules.

### 1. User Module (`/user`)
*   **GET /user/me**: Retrieve authenticated user's profile information (ID, email, user code).
*   **PATCH /user/me**: Update user details (e.g., name, affiliation).

### 2. Run Management (`/runs`)
*   **GET /runs**: List all runs the user has access to (as owner or shared). Supports filtering by name, public status.
*   **POST /runs**: Create a new run.
*   **GET /runs/{run_name}**: Get detailed configuration of a specific run (settings, structure, units).
*   **PATCH /runs/{run_name}**: Update run settings (e.g., toggle public/private, privacy policy, footer text).
*   **DELETE /runs/{run_name}**: Delete a run (careful delete).

### 3. Session Management (`/runs/{run_name}/sessions`)
*   **GET /runs/{run_name}/sessions**: List sessions for a specific run. Supports filtering by last access, completion status.
*   **POST /runs/{run_name}/sessions**: Create a new session (Migrate existing `/post/create-session` logic here).
*   **GET /runs/{run_name}/sessions/{session_code}**: Get details of a specific session (current position, progress).
*   **POST /runs/{run_name}/sessions/{session_code}/actions**: Perform actions on a session.
    *   Body: `{ "action": "pause" | "resume" | "end_external" }`

### 4. Data & Results (`/runs/{run_name}/results`)
*   **GET /runs/{run_name}/results**: Retrieve results for a run.
    *   Parameters: `sessions` (list), `surveys` (list), `items` (list), format (json, csv).
    *   *Note*: Improves upon the existing `/get/results` by making it a sub-resource of a run.

### 5. File Management (`/runs/{run_name}/files`)
*   **GET /runs/{run_name}/files**: List files uploaded to the run.
*   **POST /runs/{run_name}/files**: Upload a new file (assets, images, scripts).
*   **DELETE /runs/{run_name}/files/{filename}**: Delete a specific file.

### 6. Survey Management (`/runs/{run_name}/surveys`)
*   **GET /runs/{run_name}/surveys**: List surveys attached to a run.
*   **GET /runs/{run_name}/surveys/{survey_name}**: Get survey structure/items.

## Proposed Scopes

Scopes are designed to provide granular access control.

### General
*   `default`: Basic access, public info.

### User
*   `user:read`: Read private user profile information.
*   `user:write`: Modify user profile information.

### Run
*   `run:read`: Read run metadata, settings, and structure (non-sensitive).
*   `run:write`: Create runs, modify run settings, structure, and delete runs.

### Data (Sensitive)
*   `data:read`: Read participant data (results). *Distinct from `run:read` to protect participant privacy.*

### Session
*   `session:read`: Read session metadata (status, position).
*   `session:write`: Create new sessions, modify session state (pause, end units).

### File
*   `file:read`: List and download run files.
*   `file:write`: Upload and delete run files.

## Implementation Notes

*   **Authentication**: Continue using OAuth2 with Client Credentials Grant.
*   **Versioning**: Consider prefixing new endpoints with `/v1/` to ensure backward compatibility while migrating away from ad-hoc endpoints like `/get/results`.
*   **Response Format**: Standardize on JSON for all responses, including errors.
*   **Filtering/Pagination**: Implement standard query parameters for list endpoints (e.g., `limit`, `offset`, `sort`).
