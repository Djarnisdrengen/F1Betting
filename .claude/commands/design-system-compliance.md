---
name: design-system-compliance
description: Quality gate skill that validates design system discipline in both design handoffs from claude.ai/design AND implemented code. Use this skill EVERY TIME after creating designs in claude.ai/design or after implementing any UI code to verify design token usage, responsive behavior, and handoff completeness. Triggers include: reviewing designs, checking implementations, validating CSS/JSX, testing breakpoints, quality assurance, design system audit, token compliance check, or when the user says "review this design", "check this implementation", "validate the handoff", or "test the responsiveness". Always use this skill as the final quality check before delivering any design or code to ensure it follows design system standards.
---
 
# Design System Compliance Checker
 
A quality gate skill that enforces design system discipline by validating that designs and implementations:
- Use only design tokens (CSS variables) with no hardcoded values
- Handle all breakpoints correctly (mobile, tablet, desktop)
- Include complete handoff documentation
- Meet accessibility and interaction state requirements
## When to Use This Skill
 
Use this skill in two critical scenarios:
 
1. **After design creation in claude.ai/design** - Validate the design output before handoff to development
2. **After implementation** - Verify the code follows design system standards
This skill acts as a mandatory quality gate. Do not deliver designs or implementations without running this validation first.
 
## Validation Checklist
 
### 1. Design Token Compliance
 
**Valid design tokens**: CSS variables in the format `--token-name`
 
Scan all style declarations for hardcoded values in:
- **Colors**: Any hex (#FF5733), rgb(), rgba(), hsl(), or named colors (red, blue)
- **Spacing**: Hardcoded px, rem, em values for margin, padding, gap
- **Typography**: Hardcoded font-size, line-height, font-weight, font-family
- **Borders**: Hardcoded border-width, border-radius values
- **Shadows**: Hardcoded box-shadow values
- **Z-index**: Hardcoded z-index values
- **Transitions**: Hardcoded timing values
**Exception**: `0` values are acceptable without tokens (e.g., `margin: 0`)
 
### 1b. Font Consistency & Token Usage
 
**Standard design system font tokens**:
- `--font-heading`: For all heading elements (h1, h2, h3, h4, h5, h6)
- `--font-body`: For body text (p, div, span, li, etc.)
- `--font-mono`: For code/monospace text (code, pre)
Validate font consistency:
 
**Check 1: Headings use heading font**
- All h1, h2, h3, h4, h5, h6 elements must use `font-family: var(--font-heading)`
- Flag any heading with a different font-family value
- Flag any heading without an explicit font-family if it doesn't inherit the heading font
**Check 2: Body text uses body font**
- All body text elements (p, div, span, li, td, etc.) must use `font-family: var(--font-body)`
- Body tag should set the default: `body { font-family: var(--font-body); }`
- Flag any body text element with a different font-family
**Check 3: No random fonts appear**
- Every font-family declaration must use a design token
- Flag hardcoded fonts: `font-family: Arial, sans-serif` ✗
- Flag web fonts without tokens: `font-family: 'Inter', sans-serif` ✗
- Accept only: `font-family: var(--font-*)` ✓
**Check 4: Consistent font usage throughout**
- All headings should share the same font (via --font-heading)
- All body text should share the same font (via --font-body)
- No mixing of different fonts unless intentional (like monospace for code)
- Document any intentional font variations (e.g., special emphasis, brand elements)
### 2. Breakpoint Coverage
 
Standard breakpoints to check:
- **Mobile**: 320px - 767px
- **Tablet**: 768px - 1023px  
- **Desktop**: 1024px and above
For each breakpoint, verify:
- Layout adapts appropriately (columns collapse, stacking changes)
- Typography scales (font sizes adjust if needed)
- Spacing adjusts (padding/margins scale down on smaller screens)
- Navigation changes (hamburger menu on mobile, full nav on desktop)
- Images/media are responsive
- Interactive elements remain usable (touch targets ≥44px on mobile)
### 3. Responsive Behavior
 
Check for responsive design patterns:
- Fluid layouts using percentages, flexbox, or grid
- Media queries present for each breakpoint
- No horizontal scrolling on any breakpoint
- Content reflows naturally
- No fixed-width containers that break on smaller screens
### 4. Handoff Completeness
 
#### Component Specifications
For each component, verify documentation includes:
- **Props/Parameters**: List of configurable options
- **States**: Default, loading, error, empty states
- **Variants**: Size variants (small, medium, large), style variants (primary, secondary)
#### Responsive Behavior Notes
For each major component/section:
- How layout changes across breakpoints
- Which elements hide/show on mobile vs desktop
- Stacking order changes
#### Interaction States
For all interactive elements (buttons, links, inputs, cards):
- **Hover**: Visual feedback on mouse over
- **Focus**: Visible focus indicator for keyboard navigation
- **Active**: Pressed/clicked state
- **Disabled**: Non-interactive appearance and behavior
#### Accessibility Requirements
- **ARIA labels**: Buttons, icons, form fields have descriptive labels
- **Keyboard navigation**: Tab order is logical, all interactive elements reachable
- **Color contrast**: Text meets WCAG AA standards (4.5:1 for normal text)
- **Focus indicators**: Visible and high-contrast
- **Alt text**: All images have meaningful descriptions
## Validation Process
 
### Step 1: Identify File Type
 
Determine what you're reviewing:
- Design handoff file from claude.ai/design (HTML/CSS/JSX)
- Implemented code (HTML/CSS/JSX)
- Component library file
### Step 2: Parse Stylesheets
 
Extract all style declarations from:
- Inline styles
- `<style>` blocks
- External CSS files
- JSX className with inline objects
- Tailwind/utility classes (if applicable)
### Step 3: Scan for Violations
 
Systematically check each validation rule and collect violations.
 
### Step 4: Generate Report
 
Output format:
 
```
# Design System Compliance Report
 
## Summary
- Total violations: X
- Critical: Y (hardcoded values, font inconsistencies)
- Warning: Z (missing documentation)
 
## Violations
 
### 1. Design Token Compliance
 
**Critical: Hardcoded color values**
- Line 45: `.button { background: #FF5733; }`
  - **Fix**: Use `background: var(--color-primary);`
  
- Line 89: `.text { color: rgb(100, 100, 100); }`
  - **Fix**: Use `color: var(--color-text-secondary);`
 
**Critical: Hardcoded spacing**
- Line 120: `.container { padding: 24px; }`
  - **Fix**: Use `padding: var(--spacing-6);`
 
**Critical: Hardcoded font-family**
- Line 34: `.hero { font-family: 'Inter', sans-serif; }`
  - **Fix**: Use `font-family: var(--font-body);` or `var(--font-heading)`
 
### 1b. Font Consistency
 
**Critical: Heading not using heading font**
- Line 56: `h2 { font-family: Arial, sans-serif; }`
  - **Fix**: Use `font-family: var(--font-heading);`
  
- Line 78: `h3 { font-family: 'Roboto', sans-serif; }`
  - **Fix**: Use `font-family: var(--font-heading);`
 
**Critical: Body text not using body font**
- Line 92: `p { font-family: Georgia, serif; }`
  - **Fix**: Use `font-family: var(--font-body);`
 
**Critical: Inconsistent fonts detected**
- Multiple different fonts used throughout:
  - Headings: Arial (h2), Roboto (h3), Inter (h1)
  - Body: Georgia (p), Helvetica (div)
  - **Fix**: Standardize all headings to `var(--font-heading)` and all body text to `var(--font-body)`
 
**Warning: Missing font token definitions**
- No `--font-heading` defined in :root
- No `--font-body` defined in :root
  - **Fix**: Add to :root:
```css
:root {
  --font-heading: 'Your Heading Font', sans-serif;
  --font-body: 'Your Body Font', sans-serif;
}
```
 
### 2. Breakpoint Coverage
 
**Warning: Missing tablet layout**
- Component: `.hero-section`
  - Mobile layout defined ✓
  - Tablet layout missing ✗
  - Desktop layout defined ✓
  - **Fix**: Add media query for 768px-1023px range
### 3. Responsive Behavior
 
**Warning: Fixed width container**
- Line 67: `.content { width: 1200px; }`
  - **Fix**: Use `max-width: 1200px; width: 100%;` or `width: min(1200px, 100%);`
### 4. Handoff Completeness
 
**Warning: Missing interaction states**
- Component: `.cta-button`
  - Hover state: missing ✗
  - Focus state: missing ✗
  - Active state: defined ✓
  - **Fix**: Add :hover and :focus pseudo-classes with design tokens
**Warning: Incomplete accessibility**
- Component: `.icon-button`
  - Missing ARIA label
  - **Fix**: Add `aria-label="Close menu"` or equivalent
## Recommendations
 
1. Replace all hardcoded values with design tokens
2. Define missing breakpoint behaviors
3. Add complete interaction state styles
4. Document all accessibility requirements
```
 
## Output Guidelines
 
- **Be specific**: Include line numbers, component names, exact values
- **Prioritize**: Critical violations (hardcoded values) before warnings (missing docs)
- **Actionable fixes**: Provide exact code suggestions, not just descriptions
- **Context**: Explain WHY something is a violation (e.g., "hardcoded colors prevent theming")
- **Positive feedback**: If something is fully compliant, acknowledge it briefly
 
## Edge Cases and Special Situations
 
### Design Systems with Mixed Token Formats
 
If the design system uses a mix of CSS variables and other token formats:
- Ask the user to clarify which format is the standard
- Flag inconsistencies as violations
 
### Third-Party Components
 
If the file includes third-party UI libraries (Material-UI, Ant Design):
- Focus on custom styles only
- Flag if third-party components aren't wrapped/themed with design tokens
 
### Utility-First CSS (Tailwind)
 
If using Tailwind or similar:
- Check if Tailwind config uses design tokens
- Verify custom values aren't bypassing the design system
- Flag arbitrary values like `text-[#FF5733]` as violations
 
### Progressive Enhancement
 
Some breakpoint-specific features (like hover effects) may intentionally be absent on mobile:
- Don't flag missing hover states on mobile-only components
- Do flag missing touch-friendly alternatives
 
## Relationship to Other Skills
 
This skill complements existing skills:
 
- **frontend-design**: Use design-system-compliance AFTER frontend-design creates UI code
- **design-handoff-implementer**: Use design-system-compliance BEFORE and AFTER design-handoff-implementer runs
- **web-architecture-review**: design-system-compliance focuses on design tokens and responsiveness; web-architecture-review focuses on code structure and architecture
 
## Testing the Skill
 
When testing this skill, use these example prompts:
 
1. "Review this design for design token compliance" + paste design file
2. "Check if this implementation follows our design system" + paste code
3. "Validate the responsiveness and breakpoint coverage" + paste CSS
4. "Run a design system audit on this component library"
5. "Is this handoff detailed enough for development?"
 
Expected behavior: The skill should produce a structured violation report with specific line numbers, exact violations, and actionable fixes for each issue found.

## Known Gotcha: CSS Custom Property Alias Chains

When the design system uses short alias tokens (`--display: var(--font-display)`) defined on `:root`, overriding only the canonical token on a descendant element is **not sufficient**:

```css
/* ❌ Incomplete — var(--display) still resolves to Kalam for elements that reference the alias */
body.font-system {
    --font-display: system-ui;
}

/* ✅ Correct — override BOTH the canonical token and every alias that points to it */
body.font-system {
    --font-display: system-ui;
    --display:      system-ui;
}
```

**Why it fails:** Elements using `var(--display)` inherit the alias value (`var(--font-display)`) from `:root`. Browsers resolve the alias substitution relative to `:root`, not the element's own inherited scope, so the body-level canonical override does not propagate through the alias.

**Audit rule:** Any toggle rule (`body.dark`, `body.font-system`, etc.) must explicitly declare every alias token in addition to its canonical counterparts. Flag as **Critical** if aliases are missing from toggle rules.
