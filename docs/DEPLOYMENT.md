# 📦 Deployment Checklist - O-NET Analysis System

## Pre-Deployment

### Files to Upload
- [ ] `index.php` - Main application
- [ ] `import.php` - Data import page
- [ ] `settings.php` - Settings page
- [ ] `install.php` - Installer (will be deleted after installation)
- [ ] `db.php` - Database connection
- [ ] `functions.php` - Core functions
- [ ] `style.css` - Stylesheet
- [ ] `config.sample.php` - Configuration template
- [ ] `.htaccess` - Security settings
- [ ] `INSTALLATION.md` - Installation guide
- [ ] `README.md` - Project documentation
- [ ] `sample_data/` - Sample CSV files (optional)

### Files NOT to Upload
- [ ] ~~`onet.db`~~ - SQLite database (not needed for MySQL)
- [ ] ~~`onet_demo.db`~~ - Demo database
- [ ] ~~`install_sqlite.php`~~ - SQLite installer
- [ ] ~~`demo_setup.php`~~ - Demo setup script
- [ ] ~~`config_test.php`~~ - Test configuration
- [ ] ~~`migrate_*.php`~~ - Migration scripts
- [ ] ~~`auto_import.php`~~ - Auto import script
- [ ] ~~`config.php`~~ - Will be created by installer

---

## Hosting Setup

### 1. Database Preparation
- [ ] Create MySQL database via cPanel/phpMyAdmin
- [ ] Create MySQL user with password
- [ ] Grant ALL PRIVILEGES to user on database
- [ ] Note down: hostname, database name, username, password

### 2. File Upload
- [ ] Upload all required files via FTP/cPanel File Manager
- [ ] Verify all files uploaded successfully
- [ ] Check file structure is intact

### 3. File Permissions
```bash
chmod 644 *.php
chmod 644 *.css
chmod 644 *.md
chmod 755 sample_data/
chmod 666 settings.json  # If it needs to be writable
```

---

## Installation Process

### 1. Run Installer
- [ ] Navigate to `http://yourdomain.com/install.php`
- [ ] Fill in database credentials
- [ ] Click "ติดตั้ง" (Install)
- [ ] Verify success message appears
- [ ] Check `config.php` was created

### 2. Security
- [ ] Delete or rename `install.php`
  ```bash
  rm install.php
  # or
  mv install.php install.php.bak
  ```
- [ ] Verify `.htaccess` is working
- [ ] Test that `config.php` cannot be accessed directly

### 3. Import Data
- [ ] Go to `http://yourdomain.com/import.php`
- [ ] Import students data (students.csv or paste from Excel)
- [ ] Import indicators/questions (mapping.csv or paste from Excel)
- [ ] Import scores for each subject (scores.csv or paste from Excel)
- [ ] Verify data imported correctly

### 4. Configure Settings
- [ ] Go to `http://yourdomain.com/settings.php`
- [ ] Set percentile thresholds (p80, p60, p40, p20)
- [ ] Set weakness threshold (default: 50%)
- [ ] Configure subject-specific settings if needed
- [ ] Save settings

---

## Testing

### Functionality Tests
- [ ] **Homepage** (`index.php`)
  - [ ] Grade/Room/Subject filters work
  - [ ] Overview view displays correctly
  - [ ] Individual view displays correctly
  - [ ] Charts render properly
  
- [ ] **Import Page** (`import.php`)
  - [ ] File upload works
  - [ ] Copy-paste feature works
  - [ ] Data imports successfully
  - [ ] Error messages display correctly

- [ ] **Settings Page** (`settings.php`)
  - [ ] Can save default thresholds
  - [ ] Can save subject-specific thresholds
  - [ ] Can save weakness thresholds
  - [ ] Settings persist after page reload

### Data Integrity Tests
- [ ] Student list displays correctly
- [ ] Scores match imported data
- [ ] Indicators grouped correctly
- [ ] Subject filtering works
- [ ] Statistical calculations are accurate

### Performance Tests
- [ ] Page load time < 3 seconds
- [ ] Charts render smoothly
- [ ] Large datasets (100+ students) work
- [ ] No PHP errors in error log

---

## Post-Deployment

### Monitoring
- [ ] Set up error logging
- [ ] Monitor PHP error log regularly
- [ ] Check database size growth
- [ ] Monitor server resources

### Backup
- [ ] Set up automated database backups
- [ ] Test backup restoration
- [ ] Document backup procedure
- [ ] Store backups securely

### Documentation
- [ ] Share INSTALLATION.md with users
- [ ] Document any custom configurations
- [ ] Create user manual if needed
- [ ] Note any hosting-specific settings

---

## Troubleshooting Checklist

If something goes wrong:

1. **Check PHP Error Log**
   - Location: Usually `/tmp/php_errors.log` or via cPanel
   - Look for fatal errors, warnings

2. **Verify Database Connection**
   - Check credentials in `config.php`
   - Test connection via phpMyAdmin
   - Verify user has correct privileges

3. **Check File Permissions**
   - PHP files: 644
   - Directories: 755
   - Writable files: 666

4. **Verify PHP Extensions**
   ```bash
   php -m | grep -E 'pdo|mysql'
   ```

5. **Check .htaccess**
   - Rename temporarily to test if it's causing issues
   - Verify mod_rewrite is enabled

---

## Success Criteria

✅ System is successfully deployed when:
- [ ] Installer completes without errors
- [ ] All pages load without errors
- [ ] Data can be imported successfully
- [ ] Reports display correctly
- [ ] Settings can be saved
- [ ] No security warnings
- [ ] Performance is acceptable
- [ ] Backups are configured

---

## Rollback Plan

If deployment fails:
1. Restore previous version from backup
2. Check error logs for specific issues
3. Test on staging environment first
4. Contact hosting support if needed

---

**Deployment Date:** _____________

**Deployed By:** _____________

**Production URL:** _____________

**Database Name:** _____________

**Notes:** 
_____________________________________________
_____________________________________________
_____________________________________________
