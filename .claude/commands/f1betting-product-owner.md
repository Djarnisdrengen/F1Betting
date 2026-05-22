---
name: f1betting-product-owner
description: Senior Product Owner skill for creating epics and features for the Paddock Picks F1 betting application. Use this skill whenever the user mentions creating epics, writing features, product ownership work for Paddock Picks or F1 betting, breaking down initiatives, or asks to create structured requirements with user value, acceptance criteria, or test scenarios. Also trigger when the user mentions Paddock Picks capabilities like podium predictions, auto-scoring, race management, leaderboards, live countdowns, or asks to formalize feature ideas into epics or features. Always use this skill when the user wants to write formal requirements or acceptance criteria for any F1 betting feature.
---

# Paddock Picks Senior Product Owner Skill

This skill helps create comprehensive epics and features for the Paddock Picks F1 betting application with the proper structure and detail expected from a Senior Product Owner.

## Paddock Picks Context

Paddock Picks is an F1 podium prediction app for friends with the following key capabilities:

### Core Features
- **Podium predictions** - Users predict top-3 finishers for each race
- **Auto-scoring system** - 5 points for exact position match, 2 points for correct driver in wrong position
- **Leaderboard** - Season-long standings showing total points and rankings
- **Race management** - Admin controls for races, drivers, and results entry
- **Live race countdown** - Dynamic countdown showing time until race start (turns red under 60 minutes)
- **Mobile-first design** - Optimized touch targets, responsive tables, no-zoom inputs

### Technical Architecture
- **Frontend**: Preact + htm (no build step), served as static files
- **Backend**: PHP 8 API with PDO/MySQL, HS256 JWT authentication
- **Deployment**: simply.com shared hosting (constraint: no Node.js server processes)

### User Context
- **Primary users**: Friends competing in F1 predictions
- **Usage pattern**: Weekly engagement around race weekends
- **Device mix**: Primarily mobile, some desktop
- **Technical skill**: Non-technical users (simple UX is critical)

### Project Philosophy
- **Simplicity first**: Fewer tools, fewer steps, less complexity
- **No build step**: Direct deployment of source files
- **Mobile-first**: Touch-optimized, responsive, fast
- **Fun over formality**: Social competition between friends

## Artifact Types

### Epics
High-level feature initiatives with four required sections:
1. **User value** - Why this matters to Paddock Picks users (friends competing in predictions)
2. **User experience** - How this improves the betting/prediction experience
3. **Success metrics** - Measurable outcomes (engagement, usage, satisfaction)
4. **Acceptance criteria** - High-level success criteria in Gherkin format

### Features
Detailed implementation specifications with five required sections:
1. **Requirements** - Detailed functional and non-functional requirements
2. **User story** - User goals using "As a [user], I want to [action], so that [benefit]" format
3. **Functionality** - Detailed feature specifications, user flows, scoring logic, and mobile considerations
4. **Test scenarios** - High-level test flows in Gherkin format
5. **Test cases** - Detailed test cases in Gherkin format (especially critical for scoring logic)

## Creating Epics

When the user requests an epic (e.g., "Create a Paddock Picks epic for..."), generate a comprehensive epic following this structure:

### Epic Template

```
# Epic: [Clear, concise title]

## User Value
[Explain why this matters to users competing in F1 predictions:
- What pain point or need does this address for friends making predictions?
- How does this make the betting/competition experience better?
- What engagement or fun factor does this add?
- Any social/competitive dynamics this enables]

## User Experience
[Describe the experience improvement:
- What can users now do that they couldn't before?
- How does this fit into the race weekend workflow?
- Mobile experience considerations
- Social/competitive aspects (leaderboards, rivalries, bragging rights)]

## Success Metrics
[Define measurable outcomes:
- User engagement metrics (predictions made, return visits, active users)
- Usage patterns (mobile vs desktop, race weekend spikes)
- Social indicators (leaderboard checks, competitive engagement)
- Target improvements (quantified where possible)]

## Acceptance Criteria
Feature: [Epic title]
  Scenario: [High-level scenario 1]
    Given [initial context]
    When [user action or race event]
    Then [expected outcome]
    
  Scenario: [High-level scenario 2]
    Given [initial context]
    When [user action or race event]
    Then [expected outcome]
```

**Guidelines:**
- Focus on user value and engagement, not business ROI (this is a friends app)
- Consider mobile-first experience in all scenarios
- Think about race weekend timing and F1 calendar context
- Keep it concise but comprehensive - epics should fit on 1-2 pages
- Acceptance criteria should be high-level epic outcomes, not feature details

## Creating Features

When the user requests a feature (e.g., "Write a feature for..."), generate a detailed feature specification following this structure:

### Feature Template

```
# Feature: [Clear, specific title]

## Requirements

### Functional Requirements
- [REQ-001] [Detailed functional requirement with F1/betting context]
- [REQ-002] [Detailed functional requirement]
- [REQ-003] [Mobile/touch-specific requirement if applicable]

### Non-Functional Requirements
- [NFR-001] [Performance requirement - consider simply.com shared hosting limits]
- [NFR-002] [Mobile performance - fast load times, responsive touch]
- [NFR-003] [Simplicity requirement - no complex deployment steps]

### Technical Constraints
- [Must work on simply.com shared hosting (PHP 8, MySQL, no Node.js)]
- [No build step - direct deployment of source files]
- [Mobile-first responsive design]
- [Touch targets minimum 44px for iOS/Android]
- [Input fields minimum 16px to prevent iOS zoom]

## User Story

### Primary User Goal
[Describe what the user is trying to accomplish in the context of F1 predictions]

### User Story Format
**As a** [friend competing in Paddock Picks]
**I want to** [specific action or capability]
**So that** [desired benefit or outcome]

### User Personas
- **Active predictor**: Makes predictions for every race, checks leaderboard frequently
- **Casual participant**: Participates occasionally, mainly for big races
- **Admin user**: Manages races, enters results, maintains driver list

## Functionality

### User Flow
1. [Step 1: User action and system response]
2. [Step 2: User action and system response - consider mobile touch interactions]
3. [Step 3: User action and system response]
4. [Mobile-specific consideration if applicable]

### Detailed Specifications
[Comprehensive description of how the feature works:
- UI/UX details optimized for mobile touch
- Podium picker interactions (drag-drop, tap selection, visual feedback)
- Scoring logic details (5pts exact, 2pts wrong position)
- Race calendar integration (countdowns, timezone handling)
- Data inputs and outputs (PHP API endpoints, MySQL schema)
- Error handling and edge cases
- Mobile performance optimization]

### Scoring Logic (if applicable)
[Detail any auto-scoring calculations:
- Point allocation rules
- Tie-breaking logic
- Edge cases (DNF, DSQ, results changes)
- Leaderboard updates]

### Mobile Considerations
- Touch target sizes (minimum 44px)
- Input field sizes (minimum 16px to prevent zoom)
- Responsive table handling (horizontal scroll vs stacking)
- Hamburger navigation on small screens
- Fast loading on mobile networks

### Technical Implementation
- [PHP API endpoints and routing]
- [MySQL schema changes or queries]
- [Frontend component structure (Preact + htm)]
- [JWT authentication requirements if applicable]
- [simply.com deployment considerations]

## Test Scenarios

Feature: [Feature title]
  
  Scenario: [Happy path scenario - typical race weekend use]
    Given [initial state with F1 context]
    And [race schedule context]
    When [user action]
    Then [expected result]
    And [scoring/leaderboard update if applicable]
  
  Scenario: [Mobile interaction scenario]
    Given [user on mobile device]
    When [touch interaction]
    Then [responsive behavior]
  
  Scenario: [Scoring logic scenario - if applicable]
    Given [specific prediction and result data]
    When [auto-scoring runs]
    Then [exact points calculation]
  
  Scenario: [Error handling scenario]
    Given [initial state]
    When [invalid action or edge case]
    Then [error message or graceful handling]

## Test Cases

Feature: [Feature title]
  
  Scenario: [Specific test case 1 - scoring accuracy]
    Given [exact prediction data: P1=Verstappen, P2=Hamilton, P3=Leclerc]
    And [exact result data: P1=Verstappen, P2=Hamilton, P3=Leclerc]
    When [auto-scoring runs for this user's prediction]
    Then [user receives 15 points (5+5+5)]
    And [leaderboard updates with new total]
    And [points breakdown shows: Verstappen=5, Hamilton=5, Leclerc=5]
  
  Scenario: [Specific test case 2 - partial scoring]
    Given [prediction data: P1=Verstappen, P2=Hamilton, P3=Leclerc]
    And [result data: P1=Hamilton, P2=Verstappen, P3=Sainz]
    When [auto-scoring runs]
    Then [user receives 4 points (2+2+0)]
    And [points breakdown shows: Verstappen=2 (wrong pos), Hamilton=2 (wrong pos), Leclerc=0 (not in podium)]
  
  Scenario: [Mobile touch test case]
    Given [user on iPhone in portrait mode]
    When [user taps podium picker dropdown]
    Then [dropdown expands with 44px touch targets]
    And [input field is 16px or larger to prevent zoom]
  
  Scenario: [Race countdown test case]
    Given [race starts in 45 minutes]
    When [user views race countdown component]
    Then [countdown shows "45:00" in red]
    And [countdown ticks down every second]
  
  Scenario: [Edge case test - DNF handling]
    Given [prediction includes driver who DNFs]
    When [results entered with DNF for that driver]
    Then [scoring correctly awards 0 points for that position]
  
  Scenario: [Negative test case - late prediction]
    Given [race has already started]
    When [user attempts to submit prediction]
    Then [system blocks submission with message "Race has started"]
    And [prediction form is disabled]
```

**Guidelines:**
- Number requirements for traceability (REQ-001, NFR-001, etc.)
- Use proper Gherkin format (Feature/Scenario/Given/When/Then/And)
- Test scenarios are high-level (3-5 scenarios covering main flows)
- Test cases are detailed (5-10+ cases covering happy path, alternatives, edge cases, errors)
- **Critical**: Scoring logic test cases must include exact point calculations with sample data
- Include mobile-specific test cases (touch targets, responsive behavior, input zoom prevention)
- Consider race calendar context (upcoming races, live races, completed races)
- Test edge cases specific to F1 (DNF, DSQ, grid penalties, result changes)

## Breaking Down Epics into Features

When the user asks to break down an epic into features, follow this approach:

1. **Analyze the epic** - Review user value, experience, and success metrics
2. **Identify logical groupings** - Break down based on:
   - User journeys (prediction flow, results viewing, leaderboard checking)
   - Race weekend phases (pre-race, race day, post-race)
   - User personas (active predictor, casual user, admin)
   - Mobile vs desktop experiences
   - Scoring complexity (simple features first, complex logic later)
3. **Create 3-7 features** - Each feature should:
   - Be independently deliverable
   - Provide user value (engagement, fun, ease of use)
   - Have clear boundaries
   - Map to epic acceptance criteria
   - Consider simply.com deployment constraints
4. **Provide feature titles and brief descriptions** - Let the user select which features to expand into full specifications

### Example Breakdown Format

```
Epic: [Epic title]

Proposed Features:
1. **[Feature 1 Title]** - [Brief description with F1/betting context]
   - Complexity: [Low/Medium/High]
   - Priority: [Must-have/Nice-to-have]
   - Mobile impact: [Critical/Important/Minor]

2. **[Feature 2 Title]** - [Brief description]
   - Complexity: [Low/Medium/High]
   - Priority: [Must-have/Nice-to-have]
   - Mobile impact: [Critical/Important/Minor]

3. **[Feature 3 Title]** - [Brief description]
   - Complexity: [Low/Medium/High]
   - Priority: [Must-have/Nice-to-have]
   - Mobile impact: [Critical/Important/Minor]

Which feature(s) would you like me to expand into full specifications?
```

## Working with Inputs

The skill should handle various input types:

### High-level ideas
- "Add a rivalry tracker between friends"
- "Create team constructor predictions"
→ Ask clarifying questions about user value, engagement goals, and mobile UX

### User feedback
- "Users want to see historical predictions"
- "Mobile users have trouble with the podium picker"
→ Extract key pain points and translate into epic/feature format

### F1-specific features
- "Support sprint race predictions"
- "Add fastest lap bonus points"
→ Incorporate F1 rules and calendar context
→ Highlight scoring complexity and test requirements

### Technical constraints
- "Must work without Node.js (simply.com hosting)"
- "No build step for deployment"
→ Incorporate constraints into NFR sections
→ Highlight implications in Technical Implementation sections

### Refinement requests
- "Add more test cases for the scoring logic"
- "Expand the mobile touch scenarios"
→ Enhance specific sections while maintaining overall structure

## Special Considerations for Paddock Picks

### Scoring Logic Features
Features involving auto-scoring are **critical** and require extensive test cases:
- Include exact point calculations with sample data
- Test all scoring scenarios (exact matches, partial matches, no matches)
- Cover edge cases (DNF, DSQ, result changes, ties)
- Verify leaderboard updates correctly
- Test race-by-race and season-long scoring

### Mobile-First Features
All features must consider mobile experience:
- Touch targets (minimum 44px for iOS/Android)
- Input fields (minimum 16px to prevent iOS zoom)
- Responsive tables (horizontal scroll vs stacking)
- Hamburger navigation on small screens
- Fast loading (optimize for mobile networks)

### Race Calendar Features
Features involving race timing and schedules:
- Timezone handling (display local time + UTC reference)
- Countdown timers (live ticking, visual changes near race time)
- Prediction cutoffs (no submissions after race start)
- Race status (upcoming, live, completed)
- Season calendar management

### Admin Features
Features for race and driver management:
- Simple admin UI (admins may not be technical)
- Bulk operations (add full season calendar)
- Result entry workflows (quick and error-resistant)
- Driver list maintenance (teams, numbers, active status)
- Race rescheduling (F1 calendar changes)

### Social/Competitive Features
Features for engagement and competition:
- Leaderboard variations (overall, recent races, head-to-head)
- Prediction visibility (show friends' picks after deadline)
- Achievement/badge systems
- Prediction statistics (accuracy, streaks)
- Social sharing (results, standings)

## Output Formatting

Format all output as plain text optimized for copy-paste into development tools or planning documents:
- Use markdown formatting (headers, lists, code blocks)
- Include clear section separators
- Use consistent indentation in Gherkin scenarios
- Number requirements for easy reference
- Keep line length reasonable for readability
- Make scoring calculations explicit with sample data

## Quality Checklist

Before delivering an epic or feature, verify:
- [ ] All required sections are present
- [ ] Gherkin format is correct (Feature/Scenario/Given/When/Then)
- [ ] User value and engagement are clearly articulated
- [ ] Requirements are specific and testable
- [ ] Mobile considerations are included (touch targets, responsive design)
- [ ] Scoring logic test cases include exact calculations (if applicable)
- [ ] Technical constraints are realistic for simply.com hosting
- [ ] No build-step assumptions (fits the no-build architecture)
- [ ] Test coverage includes happy path, alternatives, edge cases, and F1-specific scenarios
- [ ] Language is clear and appropriate (avoiding over-technical jargon)
- [ ] F1 and race calendar context is considered where relevant
