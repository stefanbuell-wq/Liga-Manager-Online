# HAFO.de Security Guidelines

## Overview
This document outlines the security features and best practices for HAFO.de (LMO26).

## Implemented Security Features

### Authentication & Authorization
- ✅ bcrypt password hashing (PASSWORD_DEFAULT algorithm)
- ✅ Rate limiting: 5 login attempts → 15 minute lockout
- ✅ Session regeneration after successful login
- ✅ Session timeout: 2 hours of inactivity
- ✅ Role-based access control (admin, editor, viewer)

### Session Security
- ✅ HttpOnly cookies (prevents JavaScript access)
- ✅ SameSite=Strict (CSRF protection)
- ✅ Secure flag enabled for HTTPS
- ✅ Session ID regeneration on login
- ✅ Session timeout with activity tracking

### CSRF Protection
- ✅ CSRF tokens generated per session
- ✅ Token validation on all POST requests
- ✅ Tokens sent in API responses
- ✅ hash_equals() for constant-time comparison

### Input Validation & Output Encoding
- ✅ Prepared statements for all SQL queries (no SQL injection)
- ✅ File path validation (basename() + regex whitelist)
- ✅ Path traversal protection (realpath validation)
- ✅ XSS prevention: htmlspecialchars() for output
- ✅ INI injection prevention in LmoWriter

### HTTP Security Headers
- ✅ X-Content-Type-Options: nosniff
- ✅ X-Frame-Options: DENY (clickjacking protection)
- ✅ X-XSS-Protection: 1; mode=block
- ✅ Referrer-Policy: strict-origin-when-cross-origin
- ✅ Content-Security-Policy configured

### File Security
- ✅ .htaccess protection of sensitive directories
- ✅ Directory listing disabled
- ✅ Data directory access denied
- ✅ Config directory access denied
- ✅ Library directory access denied

### Data Validation
- ✅ LMO file format validation (extensions: .l98, .lmo)
- ✅ Integer validation for IDs and rounds
- ✅ HTTP response codes for error conditions
- ✅ Empty/null checks before processing

## Security Best Practices

### For Deployment
1. **Change default credentials immediately**
   ```bash
   Edit config/auth.php with new admin password
   Use PASSWORD_DEFAULT for bcrypt hashing
   ```

2. **Enable HTTPS**
   - Redirect HTTP to HTTPS via .htaccess
   - Install valid SSL certificate
   - Set session.cookie_secure = 1

3. **Protect sensitive files**
   ```bash
   chmod 600 config/auth.php
   chmod 700 data/
   chmod 700 data/cache/
   ```

4. **Regular backups**
   - Backup data/database.sqlite regularly
   - Store backups securely (encrypted)
   - Test restore procedures

5. **Update dependencies**
   - Keep PHP updated (7.4+ recommended)
   - Enable security updates
   - Monitor for vulnerability advisories

### For Development
1. **Never commit sensitive files**
   - Add config/auth.php to .gitignore
   - Add data/ to .gitignore (local development)
   - Use .example files for configuration templates

2. **Input validation checklist**
   - Validate file formats
   - Validate numeric inputs (type casting)
   - Trim and sanitize strings
   - Check for empty/null values

3. **Database security**
   - Use prepared statements (always!)
   - Never build SQL with string concatenation
   - Validate foreign keys
   - Use transactions for multi-step operations

4. **Error handling**
   - Don't expose sensitive error information
   - Log errors with timestamps
   - Set display_errors = 0 in production
   - Use proper HTTP status codes

## Known Security Considerations

### SQLite Limitations
- SQLite is suitable for small to medium deployments
- For high-concurrency environments, consider PostgreSQL/MySQL
- SQLite database file should be outside webroot if possible

### File Upload Security
- Currently no file upload functionality
- If adding file uploads, implement:
  - File type validation (magic bytes, not just extension)
  - Size limits
  - Unique filename generation
  - Storage outside webroot

### API Rate Limiting
- Only login endpoint has rate limiting
- Consider adding rate limiting to other endpoints for production
- Use IP-based or token-based rate limiting

## Testing Security

### Manual Testing Checklist
- [ ] Test invalid login credentials → should lock after 5 attempts
- [ ] Test CSRF token validation → POST without token should fail
- [ ] Test session timeout → inactive session should expire
- [ ] Test SQL injection via parameters → should return empty/error
- [ ] Test path traversal → ../../../etc/passwd should be blocked
- [ ] Test XSS in news title/content → should be escaped in output
- [ ] Test invalid file formats → should reject non-.l98/.lmo files

### Security Headers Verification
```bash
curl -i https://your-site.de/ | grep -i "X-Content-Type\|X-Frame\|X-XSS\|CSP"
```

## Security Contact
Report security vulnerabilities responsibly to [security contact]

## Compliance
- OWASP Top 10 protections implemented
- GDPR considerations for user data
- Privacy policy should cover data usage
- Regular security audits recommended

Last Updated: 2026-01-28
