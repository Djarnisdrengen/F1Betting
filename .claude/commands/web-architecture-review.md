# web-architecture-review

---
name: web-architecture-review
description: Architecture and code review skill for full-stack vanilla PHP web development. Use this skill when the user needs architectural guidance, code quality review, refactoring strategy, or development guidelines. Triggers include requests to review code for best practices, design system architecture, evaluate technical approaches, refactor legacy code, create development guidelines, or ensure proper error handling and testing. Always use this skill when the user mentions architecture, code review, refactoring, best practices, or asks "how should I structure this" or "is this the right approach" for PHP/MySQL/HTML/JS/CSS projects.
---
 
# Web Architecture & Code Review

## Subject of review
$ARGUMENTS

A comprehensive skill for solution architects and lead developers working with vanilla PHP, MySQL, HTML, JavaScript, and CSS. Focuses on well-structured architecture, permanent solutions, and robust engineering practices.

## Project-specific note: architecture conventions
This project uses **procedural PHP** — not OOP, MVC, Repository pattern, or Service layers. Adapt all recommendations to fit:
- Standalone `.php` page files in `public/` (each page is self-contained)
- Shared helpers via `require_once` from `public/includes/functions.php` — no classes, no DI
- `getDB()` returns a PDO singleton (function, not a class)
- `getCurrentUser()`, `requireLogin()`, `requireAdmin()`, `requireCsrf()` are global helper functions
- Tests are **Playwright (Node.js)** E2E + Node `--test` unit tests — not PHPUnit. Do not recommend PHPUnit or class-based PHP test patterns.
- When suggesting new patterns, explain how to apply them within this procedural style — do not recommend introducing MVC or class hierarchies unless the user explicitly asks to refactor toward OOP.
 
## Core Principles
 
1. **Architecture-First**: Every solution starts with proper structure
2. **No Shortcuts**: Permanent fixes only, never temporary patches
3. **Test-Driven**: Tests are mandatory for all new features and refactoring
4. **Security-Conscious**: Always consider SQL injection, XSS, CSRF, authentication
5. **Error Handling**: Robust error handling at every layer
6. **Performance**: Optimize queries, avoid N+1 problems, consider caching
## When to Use This Skill
 
### Greenfield Projects
- Designing new features or systems from scratch
- Creating development guidelines and coding standards
- Establishing project architecture and patterns
### Legacy Refactoring
- Reviewing spaghetti code and creating refactoring strategy
- Aligning legacy code with target architecture
- Modernizing outdated patterns
### Code Review
- Reviewing code snippets, functions, or entire files
- Evaluating technical approaches and alternatives
- Ensuring adherence to best practices
---
 
## Workflow by Context
 
### 1. Greenfield Architecture Design
 
When starting a new project or feature:
 
**Step 1: Understand Requirements**
- Extract functional requirements
- Identify non-functional requirements (performance, security, scalability)
- Define success criteria
**Step 2: Design Architecture**
Provide:
- **System architecture diagram** (components, data flow, dependencies)
- **Data model** (entities, relationships, constraints)
- **API/interface contracts** (if applicable)
- **Technology decisions** with rationale
- **Security architecture** (authentication, authorization, data protection)
- **Error handling strategy**
**Step 3: Create Development Guidelines**
Include:
- **Directory structure** for the project
- **Naming conventions** (files, classes, functions, variables)
- **Code organization patterns** (MVC, service layer, repository pattern)
- **Database interaction patterns** (prepared statements, transaction handling)
- **Frontend patterns** (component structure, state management)
- **Error handling standards**
- **Logging strategy**
**Step 4: Definition of Ready**
```
Definition of Ready:
□ Architecture diagram approved
□ Data model reviewed and validated
□ Security requirements identified
□ Error handling strategy defined
□ Development guidelines documented
□ Test strategy outlined
□ Performance benchmarks established
□ Documentation structure created in /docs folder
```
 
**Step 5: Implementation Plan**
Provide:
- Phased approach with milestones
- Critical path identification
- Testing strategy per phase
- Risk mitigation plan
---
 
### 2. Legacy Code Refactoring
 
When dealing with spaghetti code:
 
**Step 1: Assess Current State**
- Identify code smells and anti-patterns
- Map dependencies and coupling issues
- Identify security vulnerabilities
- Document technical debt
**Step 2: Define Target Architecture**
- Show what the code *should* look like
- Explain architectural patterns to apply
- Define separation of concerns strategy
**Step 3: Refactoring Strategy**
Provide:
- **Incremental refactoring plan** (never big-bang rewrites)
- **Priority order** (highest risk/value first)
- **Backwards compatibility approach**
- **Testing strategy** before and after changes
- **Rollback plan** if issues arise
**Step 4: Example Refactoring**
Show:
- Before/after code comparison
- Step-by-step transformation
- How to maintain functionality while improving structure
- Error handling improvements
- Security improvements
**Step 5: Definition of Done**
```
Definition of Done:
□ Code follows target architecture
□ All functions have unit tests
□ Integration tests pass
□ No security vulnerabilities
□ Error handling implemented
□ Code reviewed and approved
□ Inline comments explain complex logic
□ Documentation updated in /docs folder
□ Performance benchmarks met
```
 
---
 
### 3. Code Review
 
When reviewing code snippets or files:
 
**Step 1: Holistic Analysis**
Always consider:
- How this fits into overall architecture
- Impact on system-wide patterns
- Reusability and maintainability
- Security implications
- Performance impact
**Step 2: Review Checklist**
 
**Architecture & Design:**
- [ ] Follows single responsibility principle
- [ ] Proper separation of concerns (business logic vs presentation)
- [ ] Appropriate abstraction level
- [ ] Clear component boundaries
- [ ] Reusable and composable
**PHP Best Practices:**
- [ ] Type declarations used (strict_types=1)
- [ ] Proper visibility modifiers (public/private/protected)
- [ ] No global variables or superglobals in business logic
- [ ] Dependency injection over hard-coded dependencies
- [ ] PSR standards followed (autoloading, naming)
**Database (MySQL):**
- [ ] Prepared statements (no string concatenation)
- [ ] Proper transaction handling
- [ ] Indexes on commonly queried columns
- [ ] No N+1 query problems
- [ ] Connection pooling/reuse
- [ ] Proper error handling on failures
**Security:**
- [ ] SQL injection prevention (prepared statements)
- [ ] XSS prevention (htmlspecialchars on output)
- [ ] CSRF tokens on state-changing requests
- [ ] Authentication/authorization checks
- [ ] Password hashing (password_hash/password_verify)
- [ ] Input validation and sanitization
- [ ] Secure session handling
**Error Handling:**
- [ ] Try-catch blocks around risky operations
- [ ] Meaningful error messages (user-facing vs logs)
- [ ] Error logging (not exposed to users)
- [ ] Graceful degradation
- [ ] No sensitive information in error messages
- [ ] Proper HTTP status codes
**Frontend (HTML/JS/CSS):**
- [ ] Semantic HTML
- [ ] Progressive enhancement
- [ ] Accessible (ARIA labels, keyboard navigation)
- [ ] XSS prevention (escape user content)
- [ ] Event delegation for dynamic elements
- [ ] CSS scoping (no global styles bleeding)
**Performance:**
- [ ] Database query optimization
- [ ] Appropriate caching strategy
- [ ] Lazy loading where applicable
- [ ] Asset optimization (minification, compression)
- [ ] No blocking operations in request path
**Maintainability:**
- [ ] Clear function/variable names
- [ ] Functions under 50 lines
- [ ] Inline comments explain "why" not "what"
- [ ] Complex logic documented with comments
- [ ] No code duplication
- [ ] Magic numbers replaced with constants
**Documentation:**
- [ ] Public functions have docblocks (params, return, throws)
- [ ] Complex algorithms explained with inline comments
- [ ] API endpoints documented in /docs folder
- [ ] Database schema changes documented
- [ ] Configuration changes documented
**Step 3: Provide Feedback**
 
Format feedback as:
 
**ISSUES FOUND:**
For each issue:
- **Severity**: Critical / High / Medium / Low
- **Location**: File:line or code snippet
- **Problem**: What's wrong and why
- **Impact**: Security risk / Performance issue / Maintainability concern / etc.
- **Solution**: Specific fix with code example
**REFACTORED CODE:**
Show improved version with:
- Proper structure
- Error handling
- Security improvements
- Comments explaining key changes
**TESTS REQUIRED:**
Specify:
- Unit tests needed
- Integration tests needed
- Test scenarios to cover
- Edge cases to validate
---
 
## Best Practices Library
 
### PHP Patterns
 
**Database Connection (PDO)**
```php
<?php
declare(strict_types=1);
 
class Database {
    private static ?PDO $connection = null;
    
    public static function getConnection(): PDO {
        if (self::$connection === null) {
            try {
                self::$connection = new PDO(
                    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                    DB_USER,
                    DB_PASS,
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                    ]
                );
            } catch (PDOException $e) {
                error_log("Database connection failed: " . $e->getMessage());
                throw new DatabaseException("Unable to connect to database");
            }
        }
        return self::$connection;
    }
}
```
 
**Repository Pattern**
```php
<?php
declare(strict_types=1);
 
interface UserRepositoryInterface {
    public function findById(int $id): ?User;
    public function findByEmail(string $email): ?User;
    public function save(User $user): bool;
    public function delete(int $id): bool;
}
 
class UserRepository implements UserRepositoryInterface {
    private PDO $db;
    
    public function __construct(PDO $db) {
        $this->db = $db;
    }
    
    public function findById(int $id): ?User {
        try {
            $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $data = $stmt->fetch();
            
            return $data ? User::fromArray($data) : null;
        } catch (PDOException $e) {
            error_log("Error fetching user: " . $e->getMessage());
            throw new RepositoryException("Failed to fetch user");
        }
    }
    
    public function save(User $user): bool {
        try {
            $this->db->beginTransaction();
            
            if ($user->getId() === null) {
                // Insert
                $stmt = $this->db->prepare(
                    "INSERT INTO users (email, password_hash, name, created_at) 
                     VALUES (?, ?, ?, NOW())"
                );
                $result = $stmt->execute([
                    $user->getEmail(),
                    $user->getPasswordHash(),
                    $user->getName()
                ]);
                $user->setId((int)$this->db->lastInsertId());
            } else {
                // Update
                $stmt = $this->db->prepare(
                    "UPDATE users SET email = ?, name = ?, updated_at = NOW() 
                     WHERE id = ?"
                );
                $result = $stmt->execute([
                    $user->getEmail(),
                    $user->getName(),
                    $user->getId()
                ]);
            }
            
            $this->db->commit();
            return $result;
            
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log("Error saving user: " . $e->getMessage());
            throw new RepositoryException("Failed to save user");
        }
    }
}
```
 
**Service Layer with Error Handling**
```php
<?php
declare(strict_types=1);
 
class UserService {
    private UserRepositoryInterface $userRepository;
    private EmailService $emailService;
    
    public function __construct(
        UserRepositoryInterface $userRepository,
        EmailService $emailService
    ) {
        $this->userRepository = $userRepository;
        $this->emailService = $emailService;
    }
    
    public function registerUser(string $email, string $password, string $name): User {
        // Validation
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException("Invalid email address");
        }
        
        if (strlen($password) < 8) {
            throw new ValidationException("Password must be at least 8 characters");
        }
        
        // Check if user exists
        if ($this->userRepository->findByEmail($email) !== null) {
            throw new ValidationException("Email already registered");
        }
        
        try {
            // Create user
            $user = new User();
            $user->setEmail($email);
            $user->setPasswordHash(password_hash($password, PASSWORD_ARGON2ID));
            $user->setName($name);
            
            // Save
            $this->userRepository->save($user);
            
            // Send welcome email
            try {
                $this->emailService->sendWelcomeEmail($user);
            } catch (EmailException $e) {
                // Log but don't fail registration
                error_log("Failed to send welcome email: " . $e->getMessage());
            }
            
            return $user;
            
        } catch (RepositoryException $e) {
            error_log("Registration failed: " . $e->getMessage());
            throw new ServiceException("Unable to complete registration");
        }
    }
}
```
 
**Controller Pattern**
```php
<?php
declare(strict_types=1);
 
class UserController {
    private UserService $userService;
    
    public function __construct(UserService $userService) {
        $this->userService = $userService;
    }
    
    public function register(): void {
        // CSRF check
        if (!$this->validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->jsonResponse(['error' => 'Invalid request'], 403);
            return;
        }
        
        try {
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $name = trim($_POST['name'] ?? '');
            
            $user = $this->userService->registerUser($email, $password, $name);
            
            // Set session
            $_SESSION['user_id'] = $user->getId();
            $_SESSION['regenerate_at'] = time() + 300; // Regenerate session ID every 5 min
            
            $this->jsonResponse([
                'success' => true,
                'redirect' => '/dashboard'
            ]);
            
        } catch (ValidationException $e) {
            $this->jsonResponse(['error' => $e->getMessage()], 400);
        } catch (ServiceException $e) {
            $this->jsonResponse(['error' => 'Registration failed. Please try again.'], 500);
        }
    }
    
    private function jsonResponse(array $data, int $statusCode = 200): void {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
    
    private function validateCsrfToken(string $token): bool {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}
```
 
### MySQL Optimization Patterns
 
**Proper Indexing**
```sql
-- Index frequently queried columns
CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_orders_user_created ON orders(user_id, created_at);
 
-- Composite index for common query patterns
CREATE INDEX idx_products_category_price ON products(category_id, price);
 
-- Unique index for constraints
CREATE UNIQUE INDEX idx_users_email_unique ON users(email);
```
 
**Query Optimization**
```php
// BAD: N+1 Query Problem
$users = $db->query("SELECT * FROM users")->fetchAll();
foreach ($users as $user) {
    $orders = $db->prepare("SELECT * FROM orders WHERE user_id = ?");
    $orders->execute([$user['id']]);
    // Process orders...
}
 
// GOOD: Single query with JOIN
$stmt = $db->query("
    SELECT u.*, o.* 
    FROM users u
    LEFT JOIN orders o ON u.id = o.user_id
    ORDER BY u.id, o.created_at DESC
");
$results = $stmt->fetchAll();
 
// Group by user
$users = [];
foreach ($results as $row) {
    $userId = $row['id'];
    if (!isset($users[$userId])) {
        $users[$userId] = [
            'user' => User::fromArray($row),
            'orders' => []
        ];
    }
    if ($row['order_id']) {
        $users[$userId]['orders'][] = Order::fromArray($row);
    }
}
```
 
**Transaction Handling**
```php
try {
    $db->beginTransaction();
    
    // Multiple operations that must succeed together
    $db->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?")
       ->execute([100, $fromAccountId]);
    
    $db->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?")
       ->execute([100, $toAccountId]);
    
    $db->prepare("INSERT INTO transactions (from_account, to_account, amount) VALUES (?, ?, ?)")
       ->execute([$fromAccountId, $toAccountId, 100]);
    
    $db->commit();
    
} catch (PDOException $e) {
    $db->rollBack();
    error_log("Transaction failed: " . $e->getMessage());
    throw new TransactionException("Transfer failed");
}
```
 
### Frontend Patterns
 
**XSS Prevention**
```php
// Always escape output
<?php echo htmlspecialchars($user->getName(), ENT_QUOTES, 'UTF-8'); ?>
 
// For rich text, use a library like HTML Purifier
$config = HTMLPurifier_Config::createDefault();
$purifier = new HTMLPurifier($config);
$clean_html = $purifier->purify($untrusted_html);
```
 
**Progressive Enhancement (JavaScript)**
```html
<!-- Form works without JavaScript -->
<form action="/submit" method="POST" id="myForm">
    <input type="text" name="name" required>
    <button type="submit">Submit</button>
</form>
 
<script>
document.getElementById('myForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    // Enhanced AJAX submission
    fetch('/submit', {
        method: 'POST',
        body: new FormData(this),
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Enhanced feedback
            showSuccessMessage(data.message);
        }
    })
    .catch(error => {
        // Fallback to normal form submission
        this.submit();
    });
});
</script>
```
 
**Component Pattern (Vanilla JS)**
```javascript
class DataTable {
    constructor(elementId, options = {}) {
        this.element = document.getElementById(elementId);
        this.options = Object.assign({
            pageSize: 10,
            sortable: true,
            searchable: true
        }, options);
        
        this.data = [];
        this.currentPage = 1;
        
        this.init();
    }
    
    init() {
        this.render();
        this.attachEventListeners();
    }
    
    loadData(data) {
        this.data = data;
        this.currentPage = 1;
        this.render();
    }
    
    render() {
        // Build table HTML
        const html = this.buildTableHTML();
        this.element.innerHTML = html;
    }
    
    attachEventListeners() {
        // Event delegation
        this.element.addEventListener('click', (e) => {
            if (e.target.matches('.sort-header')) {
                this.handleSort(e.target.dataset.column);
            } else if (e.target.matches('.page-link')) {
                this.handlePageChange(parseInt(e.target.dataset.page));
            }
        });
    }
    
    handleSort(column) {
        // Sort logic
    }
    
    handlePageChange(page) {
        this.currentPage = page;
        this.render();
    }
    
    buildTableHTML() {
        // Return HTML string
    }
}
 
// Usage
const table = new DataTable('myTable', { pageSize: 20 });
table.loadData(myData);
```
 
### Documentation Standards
 
**Inline Comments - Good Practices**
```php
<?php
declare(strict_types=1);
 
class PaymentProcessor {
    /**
     * Process a payment with fraud detection and retry logic
     * 
     * @param Payment $payment The payment to process
     * @return PaymentResult The result including transaction ID
     * @throws PaymentException If payment fails after all retries
     * @throws FraudException If payment is flagged as fraudulent
     */
    public function processPayment(Payment $payment): PaymentResult {
        // Fraud check before processing to avoid unnecessary gateway calls
        if ($this->fraudDetector->isSuspicious($payment)) {
            throw new FraudException("Payment flagged for review");
        }
        
        $maxRetries = 3;
        $attempt = 0;
        
        while ($attempt < $maxRetries) {
            try {
                $result = $this->gateway->charge($payment);
                
                // Gateway may return success but with pending status
                // We need to handle this as "processing" not "completed"
                if ($result->getStatus() === 'pending') {
                    return new PaymentResult(
                        status: PaymentStatus::PROCESSING,
                        message: 'Payment pending verification'
                    );
                }
                
                return $result;
                
            } catch (GatewayTimeoutException $e) {
                $attempt++;
                
                // Exponential backoff: wait 1s, then 2s, then 4s
                if ($attempt < $maxRetries) {
                    sleep(pow(2, $attempt - 1));
                    continue;
                }
                
                // Final attempt failed - log and throw
                error_log("Payment failed after {$maxRetries} attempts: " . $e->getMessage());
                throw new PaymentException("Payment processing failed");
            }
        }
    }
    
    /**
     * Calculate transaction fee based on amount and payment method
     * 
     * Fee structure:
     * - Credit card: 2.9% + $0.30
     * - Bank transfer: $1.00 flat
     * - Crypto: 1% (minimum $0.50)
     */
    private function calculateFee(float $amount, PaymentMethod $method): float {
        // Use match for cleaner fee calculation logic
        return match($method) {
            PaymentMethod::CREDIT_CARD => ($amount * 0.029) + 0.30,
            PaymentMethod::BANK_TRANSFER => 1.00,
            PaymentMethod::CRYPTO => max($amount * 0.01, 0.50),
        };
    }
}
```
 
**What NOT to Comment**
```php
// BAD: Comments state the obvious
$total = $price + $tax; // Add price and tax
if ($user->isActive()) { // Check if user is active
    // ...
}
 
// GOOD: No comment needed - code is self-explanatory
$total = $price + $tax;
if ($user->isActive()) {
    // ...
}
 
// BAD: Commented-out code
// $oldTotal = $price * $quantity;
// return $oldTotal;
 
// GOOD: Delete dead code - use version control instead
```
 
**Complex Logic Example**
```php
/**
 * Calculate next billing date based on subscription type
 * 
 * Monthly: Same day next month (handles month-end edge cases)
 * Annual: Same date next year (handles leap year edge cases)
 */
private function calculateNextBillingDate(DateTime $current, string $interval): DateTime {
    $next = clone $current;
    
    if ($interval === 'monthly') {
        // Edge case: If current date is 31st and next month has fewer days,
        // PHP's modify() will overflow into the following month
        // Solution: Set to last day of next month if current day > last day of next month
        $next->modify('+1 month');
        
        $lastDayOfMonth = (int)$next->format('t');
        $currentDay = (int)$current->format('d');
        
        if ($currentDay > $lastDayOfMonth) {
            $next->setDate(
                (int)$next->format('Y'),
                (int)$next->format('m'),
                $lastDayOfMonth
            );
        }
    } else {
        // Annual: Just add one year
        // Leap year edge case: Feb 29 -> Feb 28 in non-leap year
        $next->modify('+1 year');
    }
    
    return $next;
}
```
 
**/docs Folder Structure**
```
/docs
├── architecture.md          # System architecture, component diagrams
├── api.md                   # API endpoint documentation
├── schema.md                # Database schema, relationships, migrations
├── setup.md                 # Installation, configuration, deployment
├── security.md              # Security practices, authentication flow
├── testing.md               # Test strategy, how to run tests
├── contributing.md          # Code style, PR process, guidelines
└── changelog.md             # Version history, breaking changes
```
 
**Documentation Templates**
 
**/docs/api.md Example**
```markdown
# API Documentation
 
## Authentication
 
All API endpoints require authentication via JWT token in the Authorization header:
```
Authorization: Bearer <token>
```
 
## Endpoints
 
### POST /api/users/register
 
Register a new user account.
 
**Request Body:**
```json
{
  "email": "user@example.com",
  "password": "securePassword123",
  "name": "John Doe"
}
```
 
**Success Response (201):**
```json
{
  "success": true,
  "user": {
    "id": 123,
    "email": "user@example.com",
    "name": "John Doe",
    "created_at": "2024-01-15T10:30:00Z"
  },
  "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9..."
}
```
 
**Error Responses:**
 
400 Bad Request - Validation failed
```json
{
  "error": "Invalid email address"
}
```
 
409 Conflict - Email already exists
```json
{
  "error": "Email already registered"
}
```
 
### GET /api/users/:id
 
Get user details by ID. Requires authentication.
 
**URL Parameters:**
- `id` (integer) - User ID
**Success Response (200):**
```json
{
  "id": 123,
  "email": "user@example.com",
  "name": "John Doe",
  "created_at": "2024-01-15T10:30:00Z"
}
```
```
 
**/docs/schema.md Example**
```markdown
# Database Schema
 
## users
 
Stores user account information.
 
| Column        | Type         | Constraints              | Description                    |
|---------------|--------------|--------------------------|--------------------------------|
| id            | INT          | PRIMARY KEY, AUTO_INC    | Unique user identifier         |
| email         | VARCHAR(255) | UNIQUE, NOT NULL         | User email (login)             |
| password_hash | VARCHAR(255) | NOT NULL                 | Argon2id hashed password       |
| name          | VARCHAR(100) | NOT NULL                 | User's full name               |
| created_at    | DATETIME     | NOT NULL, DEFAULT NOW()  | Account creation timestamp     |
| updated_at    | DATETIME     | NULL                     | Last update timestamp          |
 
**Indexes:**
- `idx_users_email` on `email` (for login lookups)
 
**Constraints:**
- Email must be unique
- Password hash must be Argon2id or bcrypt
 
## orders
 
Stores customer orders.
 
| Column      | Type         | Constraints              | Description                    |
|-------------|--------------|--------------------------|--------------------------------|
| id          | INT          | PRIMARY KEY, AUTO_INC    | Unique order identifier        |
| user_id     | INT          | FOREIGN KEY, NOT NULL    | References users.id            |
| total       | DECIMAL(10,2)| NOT NULL                 | Order total amount             |
| status      | ENUM         | NOT NULL                 | pending/processing/completed   |
| created_at  | DATETIME     | NOT NULL, DEFAULT NOW()  | Order creation timestamp       |
 
**Indexes:**
- `idx_orders_user_id` on `user_id` (for user's order list)
- `idx_orders_status_created` on `(status, created_at)` (for admin dashboard)
 
**Foreign Keys:**
- `user_id` REFERENCES `users(id)` ON DELETE CASCADE
 
**Relationships:**
- User has many Orders (1:N)
- Order belongs to User (N:1)
 
## Migrations
 
### 2024-01-15: Initial Schema
- Created users table
- Created orders table
 
### 2024-01-20: Add Order Items
- Created order_items table
- Added product_id foreign key
```
 
---
 
## Output Templates
 
### Architecture Document Template
 
```markdown
# [Feature/System Name] Architecture
 
## Overview
Brief description of what this does and why.
 
## System Architecture
[Diagram or description of components and their relationships]
 
## Components
 
### 1. [Component Name]
- **Responsibility**: What it does
- **Dependencies**: What it needs
- **Interface**: Public API/methods
 
### 2. [Component Name]
...
 
## Data Model
 
### Entities
- **User**: id, email, password_hash, name, created_at, updated_at
- **Order**: id, user_id, total, status, created_at
 
### Relationships
- User has many Orders (1:N)
 
### Constraints
- email must be unique
- user_id foreign key with CASCADE delete
 
## Security Architecture
- Authentication: Session-based with regeneration
- Authorization: Role-based access control (RBAC)
- Data protection: Password hashing (Argon2id), HTTPS only
- CSRF protection: Token validation on state-changing requests
- XSS prevention: Output escaping
 
## Error Handling Strategy
- Controller layer: Catch all exceptions, return appropriate HTTP codes
- Service layer: Business logic validation, throw domain exceptions
- Repository layer: Database errors, throw repository exceptions
- Logging: All errors logged, sensitive data excluded
 
## Performance Considerations
- Database: Indexes on commonly queried columns
- Caching: Redis for session storage and frequently accessed data
- Query optimization: Avoid N+1, use pagination
 
## Testing Strategy
- Unit tests: All business logic in services
- Integration tests: Database operations in repositories
- End-to-end tests: Critical user flows
 
## Definition of Ready
□ All requirements documented
□ Architecture reviewed and approved
□ Data model validated
□ Security requirements identified
□ Development guidelines in place
□ Test strategy defined
```
 
### Code Review Feedback Template
 
```markdown
# Code Review: [File/Feature Name]
 
## Summary
[Overall assessment - Good structure / Needs refactoring / Security concerns]
 
## Critical Issues (Must Fix)
 
### 1. SQL Injection Vulnerability
**Location**: line 45
**Problem**: User input concatenated directly into query
**Impact**: Database compromise, data theft
**Solution**:
```php
// Before
$query = "SELECT * FROM users WHERE email = '" . $_POST['email'] . "'";
 
// After
$stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
$stmt->execute([$_POST['email']]);
```
 
## High Priority Issues
 
### 1. Missing Error Handling
...
 
## Medium Priority Issues
 
### 1. Code Duplication
...
 
## Architectural Improvements
 
### 1. Separate Concerns
**Current**: Business logic mixed with presentation
**Recommended**: Use MVC pattern with service layer
**Example**:
[Show refactored structure]
 
## Refactored Code
 
```php
// Complete refactored version
[Clean, well-structured code]
```
 
## Documentation Review
 
### Inline Comments
- [ ] Complex logic explained
- [ ] "Why" not "what" comments
- [ ] No obvious/redundant comments
- [ ] No commented-out code
### Docblocks
- [ ] Public methods have @param, @return, @throws
- [ ] Complex algorithms documented
- [ ] Edge cases explained
### /docs Updates Needed
- [ ] API changes documented in /docs/api.md
- [ ] Schema changes in /docs/schema.md
- [ ] Architecture changes in /docs/architecture.md
## Tests Required
 
### Unit Tests
- Test user validation logic
- Test password hashing
- Test error scenarios
### Integration Tests
- Test database save/retrieve
- Test transaction rollback
## Definition of Done
□ All critical issues fixed
□ Error handling implemented
□ Security vulnerabilities addressed
□ Unit tests written and passing
□ Code reviewed by peer
□ Documentation updated
```
 
---
 
## Test Creation Guidelines
 
### Unit Test Structure
```php
<?php
use PHPUnit\Framework\TestCase;
 
class UserServiceTest extends TestCase {
    private UserService $userService;
    private UserRepositoryInterface $mockRepository;
    
    protected function setUp(): void {
        $this->mockRepository = $this->createMock(UserRepositoryInterface::class);
        $this->userService = new UserService($this->mockRepository);
    }
    
    public function testRegisterUserWithValidData(): void {
        // Arrange
        $email = 'test@example.com';
        $password = 'securePassword123';
        $name = 'Test User';
        
        $this->mockRepository
            ->expects($this->once())
            ->method('findByEmail')
            ->with($email)
            ->willReturn(null);
        
        // Act
        $user = $this->userService->registerUser($email, $password, $name);
        
        // Assert
        $this->assertEquals($email, $user->getEmail());
        $this->assertEquals($name, $user->getName());
        $this->assertTrue(password_verify($password, $user->getPasswordHash()));
    }
    
    public function testRegisterUserWithInvalidEmail(): void {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid email address');
        
        $this->userService->registerUser('invalid-email', 'password', 'Name');
    }
    
    public function testRegisterUserWithExistingEmail(): void {
        $email = 'existing@example.com';
        
        $existingUser = new User();
        $existingUser->setEmail($email);
        
        $this->mockRepository
            ->method('findByEmail')
            ->with($email)
            ->willReturn($existingUser);
        
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Email already registered');
        
        $this->userService->registerUser($email, 'password', 'Name');
    }
}
```
 
### Integration Test Structure
```php
<?php
use PHPUnit\Framework\TestCase;
 
class UserRepositoryIntegrationTest extends TestCase {
    private PDO $db;
    private UserRepository $repository;
    
    protected function setUp(): void {
        // Use test database
        $this->db = new PDO('mysql:host=localhost;dbname=test_db', 'user', 'pass');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $this->repository = new UserRepository($this->db);
        
        // Clean database
        $this->db->exec('DELETE FROM users');
    }
    
    protected function tearDown(): void {
        $this->db->exec('DELETE FROM users');
    }
    
    public function testSaveAndRetrieveUser(): void {
        // Create and save user
        $user = new User();
        $user->setEmail('test@example.com');
        $user->setName('Test User');
        $user->setPasswordHash(password_hash('password', PASSWORD_ARGON2ID));
        
        $this->assertTrue($this->repository->save($user));
        $this->assertNotNull($user->getId());
        
        // Retrieve user
        $retrieved = $this->repository->findById($user->getId());
        
        $this->assertNotNull($retrieved);
        $this->assertEquals($user->getEmail(), $retrieved->getEmail());
        $this->assertEquals($user->getName(), $retrieved->getName());
    }
    
    public function testTransactionRollback(): void {
        $this->db->beginTransaction();
        
        $user = new User();
        $user->setEmail('test@example.com');
        $user->setName('Test User');
        $user->setPasswordHash('hash');
        
        $this->repository->save($user);
        
        $this->db->rollBack();
        
        // User should not exist after rollback
        $retrieved = $this->repository->findByEmail('test@example.com');
        $this->assertNull($retrieved);
    }
}
```
 
---
 
## Final Checklist
 
Before considering any work complete, verify:
 
**Architecture**
- [ ] Proper separation of concerns (Controller → Service → Repository)
- [ ] Clear component boundaries and dependencies
- [ ] No business logic in controllers or views
- [ ] Reusable and maintainable structure
**Security**
- [ ] All database queries use prepared statements
- [ ] All output is escaped (htmlspecialchars)
- [ ] CSRF tokens on forms
- [ ] Authentication/authorization checks in place
- [ ] Passwords properly hashed (Argon2id or bcrypt)
- [ ] No sensitive data in error messages or logs
**Error Handling**
- [ ] Try-catch blocks around all risky operations
- [ ] Meaningful error messages for users
- [ ] Detailed error logging (server-side only)
- [ ] Graceful degradation on failures
- [ ] Proper HTTP status codes
**Performance**
- [ ] Database queries optimized (indexes, no N+1)
- [ ] Caching strategy implemented where appropriate
- [ ] No blocking operations in request path
- [ ] Assets optimized
**Testing**
- [ ] Unit tests for all business logic
- [ ] Integration tests for database operations
- [ ] Edge cases covered
- [ ] All tests passing
**Code Quality**
- [ ] Clear naming (functions, variables, classes)
- [ ] Functions under 50 lines
- [ ] No code duplication
- [ ] Inline comments explain "why" not "what"
- [ ] Complex logic documented
- [ ] PSR standards followed
**Documentation**
- [ ] Public functions have docblocks (@param, @return, @throws)
- [ ] Complex algorithms have inline explanations
- [ ] API endpoints documented in /docs/api.md
- [ ] Database schema documented in /docs/schema.md
- [ ] Architecture documented in /docs/architecture.md
- [ ] Setup/deployment documented in /docs/setup.md
**Definition of Done Met**
- [ ] Code reviewed and approved
- [ ] Tests written and passing
- [ ] Documentation updated (inline and /docs folder)
- [ ] No known bugs or security issues
- [ ] Performance benchmarks met

