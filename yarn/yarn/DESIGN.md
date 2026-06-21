# DESIGN.md - Cursor-Inspired Design System

## Overview

AI-first code editor aesthetic. Sleek dark interface with gradient accents, clean typography, and developer-focused UX.

**Best for:** Code editor UIs, developer tools, IDE interfaces, dark-themed applications

---

## Color Palette

### Background Colors
```
Primary Background: #09090b (near black)
Surface/Panel: #18181b (slightly lighter)
Border: #27272a (subtle separation)
Hover Background: #27272a
Active Background: #3f3f46
```

### Text Colors
```
Primary Text: #f4f4f5 (off-white)
Secondary Text: #a1a1aa (muted)
Tertiary Text: #71717a (dimmed)
Accent Text: #c084fc (purple accent)
```

### Accent Colors
```
Primary Accent: #c084fc (purple)
Secondary Accent: #f472b6 (pink)
Gradient Start: #c084fc
Gradient End: #f472b6
Border Accent: #52525b
```

### Semantic Colors
```
Success: #34d399 (emerald)
Warning: #fbbf24 (amber)
Error: #f87171 (red)
Info: #60a5fa (blue)
```

---

## Typography

### Font Families
```
Primary: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif
Code: 'JetBrains Mono', 'Fira Code', monospace
Display: 'Geist Sans', var(--font-primary)
```

### Type Scale
```
Heading 1: 48px / 56px line-height / 700 weight
Heading 2: 36px / 44px line-height / 600 weight
Heading 3: 28px / 36px line-height / 600 weight
Heading 4: 22px / 30px line-height / 600 weight
Body Large: 18px / 28px line-height / 400 weight
Body: 16px / 24px line-height / 400 weight
Body Small: 14px / 22px line-height / 400 weight
Caption: 12px / 18px line-height / 400 weight
Code: 14px / 22px line-height / 400 weight (monospace)
```

---

## Components

### Buttons

#### Primary Button
```
Background: Linear gradient (#c084fc → #f472b6)
Text: #ffffff
Border: None
Border Radius: 6px
Padding: 10px 16px
Font Weight: 500
Hover: Brightness 110%
Active: Brightness 95%
Transition: All 150ms ease
Shadow: 0 1px 3px rgba(192, 132, 252, 0.3)
```

#### Secondary Button
```
Background: #27272a
Text: #f4f4f5
Border: 1px solid #3f3f46
Border Radius: 6px
Padding: 10px 16px
Font Weight: 500
Hover: Background #3f3f46
Active: Background #52525b
Transition: All 150ms ease
```

#### Ghost Button
```
Background: Transparent
Text: #a1a1aa
Border: None
Border Radius: 6px
Padding: 10px 16px
Font Weight: 500
Hover: Background #27272a, Text #f4f4f5
Active: Background #3f3f46
Transition: All 150ms ease
```

### Input Fields
```
Background: #09090b
Border: 1px solid #27272a
Border Radius: 6px
Padding: 10px 12px
Text Color: #f4f4f5
Placeholder Color: #71717a
Focus Border: #c084fc
Focus Ring: 0 0 0 3px rgba(192, 132, 252, 0.1)
Hover Border: #3f3f46
Font: 16px Inter
Height: 40px
Transition: Border 150ms ease, Ring 150ms ease
```

### Cards / Panels
```
Background: #18181b
Border: 1px solid #27272a
Border Radius: 8px
Padding: 16px
Shadow: None
Hover Border: #3f3f46
Transition: Border 150ms ease
```

### Tables
```
Background: #09090b
Border Color: #27272a
Header Background: #18181b
Header Text: #f4f4f5
Row Hover: #18181b
Text Primary: #f4f4f5
Text Secondary: #a1a1aa
Padding: 12px 16px
Font Size: 14px
Border Radius: 6px
```

### Badges
```
Background: #27272a
Text: #a1a1aa
Border Radius: 100px
Padding: 4px 10px
Font Size: 12px
Font Weight: 500
```

### Icons
```
Default Size: 20px
Color: #a1a1aa
Hover Color: #f4f4f5
Active Color: #c084fc
Stroke Width: 1.5px
```

---

## Layout

### Spacing System
```
XS: 4px
SM: 8px
MD: 16px
LG: 24px
XL: 32px
2XL: 48px
```

### Border Radius
```
Small: 4px
Medium: 6px
Large: 8px
Round: 12px
Pill: 100px
```

### Shadows
```
None: none
Small: 0 1px 2px rgba(0, 0, 0, 0.3)
Medium: 0 4px 6px rgba(0, 0, 0, 0.4)
Large: 0 10px 15px rgba(0, 0, 0, 0.5)
Glow: 0 0 20px rgba(192, 132, 252, 0.2)
```

### Transitions
```
Default: all 150ms ease
Smooth: all 200ms ease
Fast: all 100ms ease
```

---

## UI Patterns

### Sidebar
```
Width: 260px (collapsible to 60px)
Background: #09090b
Border Right: 1px solid #27272a
Padding: 16px
Item Padding: 8px 12px
Item Hover: Background #18181b
Item Active: Border Left 2px #c084fc, Background #18181b
Icon Color: #a1a1aa
Text Color: #a1a1aa
Active Text: #f4f4f5
```

### Top Navigation
```
Height: 56px
Background: rgba(9, 9, 11, 0.85)
Backdrop Filter: blur(12px)
Border Bottom: 1px solid #27272a
Padding: 0 16px
Logo Size: 20px
Item Padding: 8px 12px
Item Hover: Background #18181b
```

### Code Editor Area
```
Background: #09090b
Font: JetBrains Mono 14px
Line Height: 1.6
Tab Size: 2
Gutter Width: 60px
Gutter Color: #3f3f46
Active Line: Background #18181b
Selection: Background rgba(192, 132, 252, 0.2)
Cursor: #c084fc
```

### Modal / Dialog
```
Background: #18181b
Border: 1px solid #27272a
Border Radius: 12px
Padding: 24px
Shadow: 0 20px 25px rgba(0, 0, 0, 0.5)
Backdrop: rgba(0, 0, 0, 0.7)
Max Width: 600px
```

---

## Interactions

### Hover States
- All interactive elements brighten on hover
- Borders become more visible
- Subtle scale transform (1.02x) for cards
- Transition duration: 150ms

### Active States
- Slight scale down (0.98x)
- Darker backgrounds
- Reduced opacity for icons

### Focus States
- Outline: 2px solid #c084fc
- Ring offset: 2px
- Always visible for keyboard navigation

### Loading States
- Skeleton: Gray pulses (#27272a → #3f3f46)
- Spinner: 20px, border #27272a, border-top #c084fc
- Button: Disable interaction, show spinner

---

## Accessibility

### Contrast Ratios
- All text meets WCAG AA (4.5:1 minimum)
- Large text: 3:1 minimum
- Icons: 3:1 against background

### Keyboard Navigation
- Tab order: Logical flow
- Focus visible: Always
- Skip links: For main content
- Modal trap: Focus within dialog

### Screen Reader
- All icons have aria-labels
- Forms have associated labels
- Status updates announced
- Live regions for dynamic content

---

## Usage Instructions

1. **Copy this file** to your project root as `DESIGN.md`
2. **Tell your AI agent**: "Use DESIGN.md for UI work"
3. **Reference specific sections** when requesting components
4. **Customize colors** if needed for branding

### Example Prompts

```
"Create a dashboard page following DESIGN.md"
"Build a settings form with the input styles from DESIGN.md"
"Design a modal dialog using the modal patterns"
"Create a data table with the table styling"
```

---

## Notes

- This is inspired by Cursor's design language
- Not officially affiliated with Cursor
- Adapt colors and spacing for your brand
- Maintain dark mode as primary
- Ensure gradient accents are subtle, not overwhelming
