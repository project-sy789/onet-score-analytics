# MySQL Compatibility Fix for import.php

## Problem
The import.php file was using SQLite-specific syntax `INSERT OR REPLACE` which causes errors on MySQL/MariaDB:
```
SQLSTATE[42000]: Syntax error or access violation: 1064
```

## Solution
Changed all occurrences of `INSERT OR REPLACE` to `REPLACE INTO` which is MySQL/MariaDB compatible.

## Files Changed
- `import.php` (3 locations)

## Changes Made

### Line 104 - importStudents()
```php
// Before
INSERT OR REPLACE INTO students (student_id, prefix, name, grade_level, room_number)

// After
REPLACE INTO students (student_id, prefix, name, grade_level, room_number)
```

### Line 156 - importIndicators()
```php
// Before
INSERT OR REPLACE INTO indicators (code, description, subject)

// After
REPLACE INTO indicators (code, description, subject)
```

### Line 284 - importScores()
```php
// Before
INSERT OR REPLACE INTO scores (student_id, question_number, score_obtained)

// After
REPLACE INTO scores (student_id, question_number, score_obtained)
```

## How to Apply

### Option 1: Re-upload import.php
1. Download the updated `import.php` from your local project
2. Upload to `/domains/subyai.site/public_html/ONET/import.php` (overwrite existing)

### Option 2: Edit via File Manager
1. Open `/domains/subyai.site/public_html/ONET/import.php` in cPanel File Manager
2. Find and replace (3 times):
   - Find: `INSERT OR REPLACE INTO`
   - Replace with: `REPLACE INTO`
3. Save file

### Option 3: Edit via SSH
```bash
cd /domains/subyai.site/public_html/ONET/
sed -i 's/INSERT OR REPLACE INTO/REPLACE INTO/g' import.php
```

## Verification
After applying the fix:
1. Go to https://subyai.site/ONET/import.php
2. Try importing students data again
3. Should work without SQL syntax errors

## Note
`REPLACE INTO` works identically to `INSERT OR REPLACE`:
- If record exists (based on UNIQUE key), it deletes and inserts new
- If record doesn't exist, it inserts normally
- Compatible with both MySQL and MariaDB
