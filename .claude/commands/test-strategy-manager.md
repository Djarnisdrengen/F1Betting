---
name: test-strategy-manager
description: Senior test manager skill for creating comprehensive test strategies, defining test types and scope, managing test data, creating acceptance criteria and test cases for PHP/MySQL web applications. Use this skill whenever the user mentions testing, test strategy, test plan, test cases, acceptance criteria, test data, QA, quality assurance, or asks to review testing approaches. Also trigger when the user wants to ensure proper test coverage for features, especially betting/scoring logic, or when reviewing plans from design-handoff-implementer or web-architecture-review skills.
---

# Test Strategy Manager

A senior test manager skill for creating comprehensive test strategies and test plans for PHP/MySQL web applications, with special focus on critical business logic like betting and scoring systems.

## Core Capabilities

1. **Test Strategy Development** - Create comprehensive test strategies with clear scope, test types, and purposes
2. **Test Type Definition** - Define unit, integration, E2E, manual, performance, and security tests with their specific scopes
3. **Test Data Management** - Create strategies for realistic test datasets, database seeding, and data masking
4. **Environment Scoping** - Define clear testing boundaries for Test and Live environments
5. **Acceptance Criteria** - Generate Gherkin-format acceptance criteria optimized for Claude Code
6. **Test Case Generation** - Create detailed, actionable test cases
7. **Test Plan Review** - Review and enhance testing plans from other skills

## Output Formats

All outputs are optimized for Claude Code workflows:
- **Test strategies**: Markdown documents with clear structure
- **Acceptance criteria**: Gherkin format (Given/When/Then)
- **Test cases**: Structured markdown tables with step-by-step instructions
- **Test data**: SQL seed scripts and PHP fixtures
- **Code examples**: PHP/MySQL test implementations with PHPUnit

## Test Strategy Framework

### 1. Test Strategy Development

When creating a test strategy, follow this structure:

```markdown
# Test Strategy: [Feature/Component Name]

## 1. Scope & Objectives
- **What we're testing**: [Clear description]
- **Why it matters**: [Business/technical impact]
- **Success criteria**: [Measurable goals]
- **Out of scope**: [Explicit exclusions]

## 2. Test Types Overview
[Table of test types with purpose and owner]

## 3. Risk Assessment
- **Critical paths**: [High-risk areas requiring extensive testing]
- **Edge cases**: [Boundary conditions and unusual scenarios]
- **Data integrity concerns**: [Where data quality matters most]

## 4. Test Environment Strategy
- **Test environment**: [What can/should be tested here]
- **Live environment**: [What requires production testing]
- **Environment parity**: [How to ensure environments match]

## 5. Test Data Strategy
[Detailed test data approach]

## 6. Timeline & Resources
[Testing phases and resource needs]
```

### 2. Test Type Definitions

#### Unit Tests (PHP/MySQL)
**Purpose**: Verify individual functions/methods work correctly in isolation
**Scope**: 
- Individual PHP functions and class methods
- Database query logic (without DB connection)
- Business logic calculations (scoring, point calculations)
- Data validation and transformation

**Implementation**: PHPUnit
**Coverage target**: 80%+ for critical business logic
**Execution**: Every commit (CI/CD pipeline)

#### Integration Tests (API/Database)
**Purpose**: Verify components work together correctly
**Scope**:
- API endpoints with database operations
- Multi-step workflows (place bet → process → score)
- Authentication/authorization flows
- Database transactions and rollbacks

**Implementation**: PHPUnit with database connection
**Coverage target**: All critical user flows
**Execution**: Before deployment to test environment

#### End-to-End Tests (User Flows)
**Purpose**: Verify complete user journeys work as expected
**Scope**:
- Full user workflows from UI to database
- Cross-browser compatibility
- Mobile responsive behavior
- Real-time updates and websockets

**Implementation**: Selenium/Playwright or manual testing
**Coverage target**: Top 10 user journeys
**Execution**: Before deployment to production

#### Manual Testing (Exploratory)
**Purpose**: Find unexpected issues through human exploration
**Scope**:
- New features before release
- Edge cases not covered by automation
- UX/usability concerns
- Cross-browser quirks

**Implementation**: Test case checklists
**Coverage**: Critical paths + exploratory
**Execution**: Weekly test sessions + before releases

#### Performance Tests (Load/Stress)
**Purpose**: Ensure system handles expected load
**Scope**:
- Database query performance
- API response times under load
- Concurrent user simulation
- Memory leaks and resource usage

**Implementation**: Apache JMeter or custom PHP scripts
**Coverage target**: Peak load scenarios (e.g., race start times)
**Execution**: Monthly + before major releases

#### Security Tests (Vulnerabilities)
**Purpose**: Identify and prevent security vulnerabilities
**Scope**:
- SQL injection attempts
- XSS vulnerabilities
- CSRF protection
- Authentication/session security
- Input validation

**Implementation**: Manual review + automated scanners
**Coverage target**: All user inputs and database queries
**Execution**: Per feature + quarterly full audit

### 3. Test Data Management

#### Realistic Test Datasets
Create representative data that mirrors production:

```php
// Example: F1 race season data generator
class TestDataGenerator {
    public static function generateRaceSeason($year = 2024) {
        return [
            'races' => [
                [
                    'id' => 1,
                    'name' => 'Bahrain Grand Prix',
                    'date' => '2024-03-02',
                    'circuit' => 'Bahrain International Circuit',
                    'status' => 'completed'
                ],
                // ... more races
            ],
            'drivers' => [
                ['id' => 1, 'name' => 'Max Verstappen', 'number' => 1, 'team' => 'Red Bull Racing'],
                ['id' => 2, 'name' => 'Sergio Perez', 'number' => 11, 'team' => 'Red Bull Racing'],
                // ... more drivers (minimum 20 for realistic grid)
            ],
            'results' => [
                // Realistic podium results
                ['race_id' => 1, 'position' => 1, 'driver_id' => 1],
                ['race_id' => 1, 'position' => 2, 'driver_id' => 11],
                ['race_id' => 1, 'position' => 3, 'driver_id' => 16],
            ]
        ];
    }
}
```

#### Database Seeding Strategy

**Seed Data Categories**:
1. **Static reference data** (drivers, teams, circuits) - Committed to repo
2. **Variable test data** (users, predictions, scores) - Generated on-demand
3. **Edge case data** (unusual scenarios) - Explicit test fixtures

```sql
-- Example: Seeding test predictions with various scoring scenarios
INSERT INTO predictions (user_id, race_id, p1_driver_id, p2_driver_id, p3_driver_id, created_at)
VALUES
    -- Perfect prediction (5 + 5 + 5 = 15 points)
    (1, 1, 1, 11, 16, '2024-03-01 10:00:00'),
    
    -- Wrong position prediction (2 + 2 + 2 = 6 points)
    (2, 1, 11, 1, 16, '2024-03-01 10:05:00'),
    
    -- Partially correct (5 + 0 + 0 = 5 points)
    (3, 1, 1, 44, 63, '2024-03-01 10:10:00'),
    
    -- Late prediction (after deadline - 0 points)
    (4, 1, 1, 11, 16, '2024-03-02 15:00:00');
```

#### Data Masking/Anonymization

For production data copies in test environments:

```php
class DataMasker {
    public static function maskUserData($userId) {
        return [
            'email' => 'test_user_' . $userId . '@example.com',
            'name' => 'Test User ' . $userId,
            'password_hash' => password_hash('TestPassword123!', PASSWORD_DEFAULT),
            'ip_address' => '127.0.0.' . ($userId % 255),
        ];
    }
    
    public static function sanitizeDatabase($conn) {
        // Mask personal data while preserving relationships
        $conn->query("UPDATE users SET 
            email = CONCAT('test_user_', id, '@example.com'),
            name = CONCAT('Test User ', id),
            ip_address = '127.0.0.1'
        ");
    }
}
```

### 4. Environment Scoping

#### Test Environment
**Purpose**: Safe environment for development and testing
**Can test**:
- New features before production
- Breaking changes
- Database migrations
- Performance under simulated load
- Security vulnerability scans

**Should test**:
- All automated tests (unit, integration, E2E)
- Manual exploratory testing
- User acceptance testing (UAT)

**Cannot test**:
- Real user behavior patterns
- Production scale/load
- Third-party integrations with production keys

**Configuration**:
```php
// config/test.php
return [
    'database' => [
        'host' => 'localhost',
        'name' => 'paddock_picks_test',
        'user' => 'test_user',
    ],
    'debug' => true,
    'error_reporting' => E_ALL,
    'api_keys' => [
        'f1_data' => 'test_api_key_sandbox',
    ],
];
```

#### Live/Production Environment
**Purpose**: Real user-facing application
**Can test**:
- Smoke tests after deployment
- Monitoring and alerting
- Real user behavior analytics
- Production performance metrics

**Should test**:
- Critical path smoke tests (login, place prediction, view leaderboard)
- Database backup/restore procedures
- Incident response procedures

**Cannot test**:
- Destructive operations
- Load testing that impacts real users
- Database migrations without backup

**Configuration**:
```php
// config/production.php
return [
    'database' => [
        'host' => 'production-db.simply.com',
        'name' => 'paddock_picks',
        'user' => 'prod_user',
    ],
    'debug' => false,
    'error_reporting' => E_ERROR | E_WARNING,
    'api_keys' => [
        'f1_data' => getenv('F1_API_KEY'),
    ],
];
```

### 5. Acceptance Criteria (Gherkin Format)

Use Given/When/Then format for all acceptance criteria:

```gherkin
Feature: Podium Prediction Scoring

  Background:
    Given the race "Bahrain Grand Prix 2024" exists
    And the race deadline is "2024-03-02 14:00:00 UTC"
    And the actual podium is:
      | Position | Driver          |
      | 1        | Max Verstappen  |
      | 2        | Sergio Perez    |
      | 3        | Charles Leclerc |

  Scenario: Perfect prediction scores 15 points
    Given user "Alice" made a prediction:
      | Position | Driver          |
      | 1        | Max Verstappen  |
      | 2        | Sergio Perez    |
      | 3        | Charles Leclerc |
    When the race results are processed
    Then user "Alice" should have 15 points for this race
    And the breakdown should be:
      | Position | Points | Reason         |
      | 1        | 5      | Correct driver |
      | 2        | 5      | Correct driver |
      | 3        | 5      | Correct driver |

  Scenario: Wrong position prediction scores 2 points each
    Given user "Bob" made a prediction:
      | Position | Driver          |
      | 1        | Sergio Perez    |
      | 2        | Max Verstappen  |
      | 3        | Charles Leclerc |
    When the race results are processed
    Then user "Bob" should have 9 points for this race
    And the breakdown should be:
      | Position | Points | Reason                              |
      | 1        | 2      | Correct driver, wrong position      |
      | 2        | 2      | Correct driver, wrong position      |
      | 3        | 5      | Correct driver, correct position    |

  Scenario: Late prediction scores 0 points
    Given user "Charlie" made a prediction at "2024-03-02 15:00:00 UTC"
    And the prediction was after the race deadline
    When the race results are processed
    Then user "Charlie" should have 0 points for this race
    And an alert should be shown: "Prediction submitted after deadline"

  Scenario: Missing driver in prediction
    Given user "Diana" made a prediction with only 2 drivers
    When attempting to submit the prediction
    Then the system should reject the prediction
    And show error: "Please select all 3 podium positions"
```

### 6. Test Case Generation

Generate detailed test cases in this format:

| Test ID | Test Case | Preconditions | Test Steps | Expected Result | Priority | Type |
|---------|-----------|---------------|------------|-----------------|----------|------|
| TC001 | Verify perfect prediction scoring | User logged in, race exists, results published | 1. Create prediction: P1=Ver, P2=Per, P3=Lec<br>2. Set actual results: P1=Ver, P2=Per, P3=Lec<br>3. Run scoring job<br>4. Check user score | Score = 15 points (5+5+5) | High | Integration |
| TC002 | Verify wrong position scoring | User logged in, race exists, results published | 1. Create prediction: P1=Per, P2=Ver, P3=Lec<br>2. Set actual results: P1=Ver, P2=Per, P3=Lec<br>3. Run scoring job<br>4. Check user score | Score = 9 points (2+2+5) | High | Integration |
| TC003 | Verify deadline enforcement | User logged in, race in progress | 1. Attempt to submit prediction after deadline<br>2. Check response | Prediction rejected with error message | High | Unit |
| TC004 | Verify leaderboard calculation | 5 users with different scores | 1. Process all user scores<br>2. Query leaderboard<br>3. Verify ordering | Users sorted by total points descending | Medium | Integration |

### 7. Critical Business Logic Testing

#### Betting/Scoring Logic (Paddock Picks Focus)

**Core Scoring Rules to Test**:
1. **Exact match**: Driver predicted in correct position = 5 points
2. **Wrong position**: Driver in podium but wrong position = 2 points
3. **Miss**: Driver not in podium = 0 points
4. **Deadline**: Predictions after race start = 0 points (entire prediction void)
5. **Accumulation**: User's total score = sum of all race scores

**Edge Cases to Test**:

```php
class ScoringTestCases {
    /**
     * Edge Case 1: All drivers wrong
     */
    public function testAllDriversWrong() {
        $prediction = ['HAM', 'NOR', 'ALO'];
        $actual = ['VER', 'PER', 'LEC'];
        $score = $this->scorer->calculateScore($prediction, $actual);
        $this->assertEquals(0, $score);
    }
    
    /**
     * Edge Case 2: One driver correct but completely wrong positions
     */
    public function testOneDriverPartialMatch() {
        $prediction = ['VER', 'HAM', 'NOR']; // VER in P1
        $actual = ['PER', 'LEC', 'VER'];     // VER in P3
        $score = $this->scorer->calculateScore($prediction, $actual);
        $this->assertEquals(2, $score); // Only wrong-position points
    }
    
    /**
     * Edge Case 3: Duplicate driver in prediction (validation)
     */
    public function testDuplicateDriverRejected() {
        $this->expectException(ValidationException::class);
        $this->validator->validate(['VER', 'VER', 'LEC']);
    }
    
    /**
     * Edge Case 4: Exactly at deadline boundary
     */
    public function testDeadlineBoundary() {
        $raceStart = new DateTime('2024-03-02 14:00:00 UTC');
        
        // 1 second before - should accept
        $validPrediction = $this->createPrediction('2024-03-02 13:59:59 UTC');
        $this->assertTrue($this->validator->isBeforeDeadline($validPrediction, $raceStart));
        
        // Exactly at start - should reject
        $exactPrediction = $this->createPrediction('2024-03-02 14:00:00 UTC');
        $this->assertFalse($this->validator->isBeforeDeadline($exactPrediction, $raceStart));
    }
    
    /**
     * Edge Case 5: Race result incomplete (only 2 drivers finished)
     */
    public function testIncompleteRaceResult() {
        $prediction = ['VER', 'PER', 'LEC'];
        $actual = ['VER', 'PER', null]; // P3 missing (race incident)
        $score = $this->scorer->calculateScore($prediction, $actual);
        $this->assertEquals(10, $score); // 5+5+0, P3 not scored
    }
    
    /**
     * Edge Case 6: Tied users in leaderboard
     */
    public function testLeaderboardTiebreaker() {
        $userA = ['total_points' => 100, 'last_scored_at' => '2024-03-15'];
        $userB = ['total_points' => 100, 'last_scored_at' => '2024-03-14'];
        
        $leaderboard = $this->leaderboard->rank([$userA, $userB]);
        
        // Earlier scorer ranks higher in ties
        $this->assertEquals($userB['id'], $leaderboard[0]['id']);
    }
}
```

**Performance Testing for Scoring**:
```php
public function testBatchScoringPerformance() {
    // Generate 1000 users with predictions
    $users = TestDataGenerator::generateUsers(1000);
    $predictions = TestDataGenerator::generatePredictions($users, $raceId);
    
    $startTime = microtime(true);
    $this->scorer->batchScoreRace($raceId);
    $duration = microtime(true) - $startTime;
    
    // Should complete within 5 seconds for 1000 users
    $this->assertLessThan(5.0, $duration);
    
    // Verify all scores calculated correctly
    $scores = $this->db->query("SELECT COUNT(*) FROM scores WHERE race_id = ?", [$raceId]);
    $this->assertEquals(1000, $scores);
}
```

### 8. Reviewing Test Plans from Other Skills

When reviewing test plans from `design-handoff-implementer` or `web-architecture-review`:

**Review Checklist**:

1. **Test Coverage Gaps**
   - [ ] Are all user stories covered by acceptance criteria?
   - [ ] Are edge cases identified and tested?
   - [ ] Are error paths tested (not just happy paths)?
   - [ ] Is security testing included for all user inputs?

2. **Test Data Completeness**
   - [ ] Is test data representative of production?
   - [ ] Are boundary conditions tested (min/max values)?
   - [ ] Is data cleanup strategy defined?
   - [ ] Are data dependencies documented?

3. **Environment Strategy**
   - [ ] Is test environment configuration documented?
   - [ ] Are environment differences from production documented?
   - [ ] Is deployment/rollback tested?
   - [ ] Are database migrations tested?

4. **Integration Points**
   - [ ] Are all external API integrations tested?
   - [ ] Are authentication flows tested end-to-end?
   - [ ] Are database transactions tested for atomicity?
   - [ ] Are race conditions tested for concurrent operations?

5. **Performance & Scale**
   - [ ] Are performance benchmarks defined?
   - [ ] Is load testing included for critical paths?
   - [ ] Are database query performance tests included?
   - [ ] Is caching tested?

**Review Output Format**:

```markdown
# Test Plan Review: [Feature Name]

## Summary
[Overall assessment of test plan completeness]

## Strengths
- ✅ [Positive finding 1]
- ✅ [Positive finding 2]

## Gaps Identified
- ⚠️ **[Gap category]**: [Description]
  - **Impact**: [Risk level and consequences]
  - **Recommendation**: [Specific action to address]
  
## Enhanced Test Coverage

### Additional Acceptance Criteria
[Gherkin scenarios for gaps found]

### Additional Test Cases
[Test case table for missing scenarios]

### Test Data Enhancements
[SQL scripts or fixtures to add]

## Risk Assessment
| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| [Risk description] | High/Med/Low | High/Med/Low | [Mitigation strategy] |
```

## Usage Examples

### Example 1: Full Test Strategy Request
**User**: "Create a test strategy for the new betting feature in Paddock Picks"

**Response**:
1. Generate complete test strategy document
2. Define all 6 test types with specific scope
3. Create test data seeding scripts
4. Generate 10-15 Gherkin acceptance criteria
5. Create 20-30 detailed test cases
6. Identify critical edge cases for betting logic

### Example 2: Review Request
**User**: "Review the testing plan from design-handoff-implementer"

**Response**:
1. Read the implementation plan
2. Apply review checklist
3. Identify coverage gaps
4. Generate additional acceptance criteria for gaps
5. Recommend test data enhancements
6. Output comprehensive review document

### Example 3: Acceptance Criteria Only
**User**: "Write acceptance criteria for the user registration flow"

**Response**:
Generate 8-12 Gherkin scenarios covering:
- Happy path registration
- Validation errors (email, password, etc.)
- Duplicate user handling
- Email verification
- Edge cases (special characters, long names, etc.)

## Integration with Existing Skills

This skill enhances `design-handoff-implementer` and `web-architecture-review` by:
1. Automatically reviewing their test plans when invoked
2. Generating comprehensive acceptance criteria for their implementations
3. Creating test data strategies aligned with their architecture
4. Ensuring test coverage for all edge cases they identify

## Best Practices

1. **Start with critical business logic** - Test betting/scoring thoroughly before UI
2. **Write tests first** - Use TDD for new features (write acceptance criteria → write test → implement)
3. **Maintain test data fixtures** - Keep seed data in version control
4. **Run tests on every commit** - CI/CD pipeline must run all unit/integration tests
5. **Review test coverage weekly** - Identify gaps and add tests proactively
6. **Keep tests fast** - Unit tests <100ms, integration tests <5s
7. **Document test data requirements** - Future developers need to understand test setup
8. **Test in production-like conditions** - Use production data snapshots (masked) when possible

## Common Anti-Patterns to Avoid

❌ **Don't**: Write tests that depend on execution order
✅ **Do**: Make each test independent and isolated

❌ **Don't**: Use production database for testing
✅ **Do**: Use dedicated test database with seed data

❌ **Don't**: Test implementation details
✅ **Do**: Test public interfaces and expected behavior

❌ **Don't**: Skip edge cases because "users won't do that"
✅ **Do**: Test every edge case - users will find them

❌ **Don't**: Write tests after bugs are found
✅ **Do**: Write tests before features are implemented

## Quick Reference

**Scoring Logic**: 5pts exact, 2pts wrong position, 0pts miss
**Deadline**: Race start time (no predictions after)
**Test DB**: `paddock_picks_test`
**PHPUnit**: `vendor/bin/phpunit tests/`
**Seed data**: `php scripts/seed-test-data.php`