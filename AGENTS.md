# Production and Business Operation Record Management System UI Rules

## Purpose

This file stores the full UI, role access, approval workflow, report template, table system, modal, accessibility, and color palette rules for the Production and Business Operation Record Management System.

## System Context

Redesign the full Production and Business Operation Record Management System UI.

The system is for Mindoro State University Bongabong Campus Production and Business Operation Record Management.

It includes POS, sales records, cash flow, inventory, fishpond operations, rental operations, proposal requests, logbook, reports, landing page content, user management, security logs, and database backup.

Main goal: Make the system cleaner, easier to understand, easier to operate, less redundant, high contrast, professional, and consistent across all pages.

## Design Goals

### 1. Remove Redundancy

- Remove repeated labels, repeated descriptions, repeated section titles, repeated buttons, and unnecessary helper text.
- Do not show the same action twice unless needed.
- Shorten long page subtitles.
- Avoid unnecessary cards and charts on work pages.
- Keep labels direct and consistent.

### 2. Improve Usability

- Group related controls together.
- Make primary actions easy to find.
- Make workflows direct.
- Use consistent layouts across all pages.
- Make pages easier to scan in one look.
- Use compact filters and clean tables.
- Preserve all existing system functions.

### 3. Apply Strong Visual Hierarchy

- Page title should be most visible.
- Primary action should appear near the page title.
- Filters should appear before tables.
- Tables should have clear headers, readable spacing, and status badges.
- Important warnings, overdue records, low stock, pending approvals, and financial totals should stand out.
- Secondary actions should be less dominant than primary actions.

### 4. Use the Uploaded Color Palette

Use the attached palette as the main visual theme.

Palette role:

- Dark green = primary brand color, sidebar, active controls, main buttons.
- Golden yellow = highlight, active emphasis, warning, selected states.
- Deep green = hover states, secondary accent, chart contrast.
- Light green = success state, soft badge, table header, supporting panel.
- White or off-white = page background, cards, tables, forms, modals.

Suggested background and foreground:

- Main page background: `#F7F9F6`
- Card background: `#FFFFFF`
- Sidebar background: dark green
- Active sidebar item: golden yellow
- Table header background: soft light green
- Main text: dark green-black
- Secondary text: muted dark gray-green
- Sidebar text: white
- Active sidebar text: dark green-black
- Primary button: dark green with white text
- Primary hover: deeper green
- Secondary button: white with dark green border and text
- Success badge: light green background with dark green text
- Warning badge: golden yellow background with dark text
- Danger badge: light red background with dark red text

### 5. Use High Contrast

- Make all text, buttons, badges, tables, filters, forms, and modals readable.
- Use strong foreground and background contrast.
- Make interactive controls clear.
- Follow accessibility-friendly contrast similar to WCAG AA or better.

## Global Layout Rules

- Expand the main content area.
- Current pages look too narrow and leave too much empty space on the right.
- Use a responsive content container around 1180px to 1280px wide.
- Use consistent spacing between page title, subtitle, buttons, filters, tabs, tables, and modals.
- Use the same page structure across modules.

Recommended page structure:

- Page header:
  - Title
  - Short subtitle
  - Primary action button on the right
- Content:
  - Filters if needed
  - Tabs if needed
  - Table or main working area
  - Pagination and summary footer

## Sidebar Rules

- Keep the sidebar.
- Keep module groups:
  - Overview
  - Sales
  - Inventory
  - Income Generating Projects
  - Records
  - Admin
- Improve active state using the palette.
- Make active page clear.
- Keep labels short.

Suggested sidebar label changes:

- Products & Services = Inventory
- Project Dashboard = Project Summary
- Security Logs = Security and Audit Logs
- Landing Page = Landing Page Content

## Dashboard Rules

- The Dashboard should be the main visual summary page.
- Keep cards and charts on the Dashboard.
- Make the Dashboard clean, informational, and easy to scan.

Dashboard cards:

- Today Sales
- Net Cash Today
- Low Stock Items
- Overdue Rentals
- Released Toga
- Pending Proposal Requests
- Pending Approval Requests
- Total Inventory Value
- Fishpond Net Income
- Rental Net Income
- Recent Logbook

Dashboard charts:

- Sales Trend
- Cash In vs Cash Out
- Top Product Profit
- Inventory Stock Status
- Income by Project
- Rental Collection Status

Dashboard layout:

- Top section = key operation cards
- Middle section = financial and operation charts
- Bottom section = alerts and recent activity

Dashboard card rules:

- Make cards compact.
- Use short text only.
- Use clear values.
- Use status colors.
- Avoid long descriptions inside cards.

Dashboard card navigation:

- Make every dashboard card clickable and route to its related page.
- Today Sales = POS or Sales Records
- Net Cash Today = Cash Flow
- Low Stock Items = Inventory, Low Stock tab
- Overdue Rentals = Rental Operations, Overdue tab
- Released Toga = Rental Operations, Toga Rentals tab
- Pending Proposals = Proposal Requests, Pending tab
- Pending Approvals = Approval Requests page
- Inventory Value = Inventory Catalog
- Fishpond Net Income = Fishpond Operations, Performance tab
- Rental Net Income = Rental Operations, Payments or Performance tab
- Recent Logbook = Logbook page

Interactive card behavior:

- Use cursor pointer.
- Add hover state.
- Add keyboard focus state.
- Add a small arrow or View details text.
- Support Enter key navigation.
- Add aria-label for accessibility.

Dashboard chart hover:

- Add tooltips to every chart.
- Tooltips should show useful data when hovering over a point, bar, or segment.

Tooltip content:

- Sales Trend = date, revenue, profit, transactions
- Cash Movement = date, cash in, cash out, net cash
- Top Product Profit = product, quantity sold, revenue, cost, profit
- Inventory Stock Status = item or category, current stock, low stock count, stock value
- Income by Project = project name, income, expense, net income
- Rental Collection = rental account, expected amount, paid amount, balance, due date

Tooltip style:

- Dark green background
- White text
- Golden yellow highlight for key value
- Rounded box
- Small shadow
- Readable label and value format

## Other Page Rules

- Do not add cards and charts to pages where they are not needed.
- Work pages should focus on filters, tables, forms, and actions.
- Use cards only where summary values matter:
  - Dashboard
  - Reports
  - Cash Flow
  - Inventory
  - Sales Records, optional only for totals
- Avoid cards on:
  - Proposal Requests
  - Logbook
  - User Management
  - Security Logs
  - Backup
  - Landing Page Content

## POS Page

- Keep product grid and checkout panel.
- Keep Add Product and View Sales Records.
- Make product cards smaller.

Each product card should show:

- Product or service name
- SKU
- Price
- Stock or Service label
- Quantity input
- Add button

POS rules:

- Remove Modify from product cards unless editing during POS is required.
- Prefer editing products inside Inventory.
- Keep checkout panel sticky on the right.
- Keep Student Name and Student ID because POS entries create logbook records.

## Sales Records Page

- Use filters, summary totals, and table.
- Add small summary totals only if needed:
  - Total Revenue
  - Total Cost
  - Total Profit
  - Transactions
- Rename confusing headings.
- Use Sales Transactions as the main table title.

Keep columns:

- Date and Time
- Product
- SKU
- Quantity
- Unit Price
- Total Amount
- Total Cost
- Profit
- OR Number

## Cash Flow Page

- Use filters, small financial summary, and table.

Keep summary values:

- Cash In
- Cash Out
- Net Cash
- Total Transactions

Rename:

- Direction = Type
- Source Module = Source
- OR Number = Reference No.

Cash Flow rules:

- Keep Add Transaction modal.
- Use Cash In and Cash Out badges with strong contrast.

## Inventory Page

- Use filters, limited summary, and table.

Limit summary cards to:

- Active Items
- Low Stock
- Stock Value

Use table columns:

- Item
- Group
- Category
- Cost
- Selling Price
- Profit
- Stock
- Status
- Actions

Inventory rules:

- For services, show Service under Stock.
- Do not overload the Pricing column with stacked text.
- Use action menu for edit, stock movement, and view ledger.

## Fishpond Operations

- Use filters, tabs, and tables only.

Tabs:

- Accounts
- Entries
- Overdue
- Performance

Change modal title:

- Record Category Entry = Add Fishpond Entry

Use clearer entry types:

- Monitoring
- Harvest Income
- Expense
- Maintenance

Use terms:

- Pond Account
- Activity Entry
- Harvest Record
- Expense Record
- Monitoring Record

Fishpond rules:

- Keep Also post to Cash Flow for money-related entries.
- Monitoring-only entries should not require cash posting.

## Rental Operations

- Use filters, tabs, and tables only.
- Separate Stall Rentals and Toga Rentals clearly.

Stall tabs:

- Stall Accounts
- Payments
- Overdue

Toga tabs:

- Toga Releases
- Rental Activity
- Overdue

Rename actions:

- New Stall = Add Stall Account
- Edit Rental = Edit Account
- Add Account = Add Rental Account
- Release Toga = Add Toga Release
- Record Payment = Add Payment

Use separate modal titles based on context:

- Add Stall Payment
- Add Toga Release
- Add Rental Entry

## Proposal Requests

- Keep page simple.

Use tabs:

- All
- Pending
- Approved
- Rejected
- Needs Revision

Proposal Requests rules:

- Keep one Submit Proposal button near the page title.
- Remove duplicated Submit Proposal button in the empty state.

Empty state:

- No proposal requests yet.
- Submit a project proposal for review and tracking.

Proposal modal fields:

- Title
- Proposer
- Department
- Estimated Budget
- Target Date
- Summary

Proposal status:

- Status should default to Pending.

Proposer field:

- Do not use free text for Proposer.
- Replace Proposer with a searchable person selector.
- The user should search and select a proposer from a centralized People or Personnel master list.
- Search by full name, ID, department, or role.
- Save proposer_id in the proposal record.
- Display proposer name from the People table.

People or Personnel master list fields:

- id
- full_name
- person_code
- department
- role_or_position
- contact_info
- status
- created_by
- approved_by
- created_at
- updated_at

When a proposer is selected:

- Auto-fill Department from the selected person record.
- Allow admin-controlled correction if needed.

Add Person workflow:

- Add a small Add Person option beside the Proposer field.
- Admin users add and edit people directly.
- Staff users submit new person entries as pending requests for admin approval.
- After approval, the new person becomes selectable.

Apply the same searchable person selector wherever consistent person names are needed:

- Proposal Requests
- Rental Accounts
- Toga Releases
- Logbook Entries
- Approved By fields
- Requested By fields

## Logbook

- Rename Student Office Logbook to Office Logbook.
- Subtitle: Records student transactions, visits, and service requests.
- Use filters and table only.
- No cards.

Keep columns:

- Date
- Student Name
- Student ID
- Time In
- Time Out
- Purpose

## Reports

- Reports should have controls and printable report output.

Keep tabs:

- Sales
- Cash Flow
- Projects
- Inventory

Report rules:

- Keep report summary cards only inside report view.

Rename:

- Sales Revenue = Revenue
- Sales Profit = Profit
- Cash Net = Net Cash
- Project Net = Project Net

## Print Report Requirement

- Do not print a screenshot of the webpage.
- Create a separate professional print report template.
- Use an A4 document layout.

Printed report should include:

- Mindoro State University Bongabong Campus
- Production and Business Operation Record Management System
- Report Title
- Report Period
- Generated Date
- Generated By
- Summary Totals
- Detailed Tables
- Prepared By
- Reviewed By
- Approved By
- Signature Lines
- Footer Notes
- Page Number

Print-specific CSS:

- Hide sidebar
- Hide navigation
- Hide buttons
- Hide filters
- Hide tabs if not part of the printed report
- Hide browser-style UI layout
- Use white background
- Use black text
- Use clear table borders
- Use readable font size
- Use proper A4 margins
- Use page breaks where needed
- Repeat table headers on new pages

Printed reports should look like official office documents.

Use the green and yellow palette for the web interface.

Use formal black-and-white layout for printed reports.

## Landing Page Content

- Rename page to Landing Page Content.

Group content into:

- Hero Section
- Services Section
- Contact Section
- Add New Section

Each card should show:

- Section name
- Title
- Body
- Active toggle
- Save button

Landing Page Content rules:

- Align save buttons consistently.
- Use simple form layout.
- No cards beyond section form panels.

## User Management

- Use strict admin control.
- Do not let staff access this page.

Table columns:

- Name
- Username
- Role
- Status
- Approved By
- Actions

User Management rules:

- Replace large inline action controls with one action menu.

Action menu:

- Edit Role
- Approve
- Suspend
- Reset Password

Add User modal fields:

- Username
- Full Name
- Temporary Password
- Role
- Status

Password rule:

- Do not prefill password with hidden dots.
- Use placeholder: Enter temporary password

## Security and Audit Logs

- Admin only.
- Use filters and table only.

Separate log types:

- Audit Logs
- Login Sessions
- Error Logs

Security and Audit Logs rules:

- Do not show raw JSON by default.
- Show readable details instead.
- Example: Viewed daily report for May 9, 2026
- Add View raw details only inside an expandable detail view.

## Backup

- Admin only.
- Keep clean page.
- No cards.

Show:

- Create Backup Now
- Backup Files table

Backup table columns:

- File Name
- Created
- Size
- Download

Backup rules:

- Add Restore only if safe restore is fully supported.
- Add short helper text: Store downloaded backups outside the local server.

## Table System

Standardize all tables across the system.

Table card structure:

- Header:
  - Table title on left
  - Record count badge on right
- Toolbar:
  - Search input on left
  - Column filter, sort, and rows dropdown on right
- Table:
  - Clear column headings
  - Readable row height
  - Status badges
  - Action column
  - Consistent spacing
  - High contrast
- Footer:
  - Total count
  - Pagination
  - Summary totals for financial pages only

Apply this table structure to:

- Sales Records
- Cash Flow
- Inventory
- Fishpond Operations
- Rental Operations
- Logbook
- Reports
- Users
- Security Logs
- Backup
- Proposal Requests

## Modal and Form Rules

- Use consistent modal width.
- Use shorter modal titles.
- Group related fields.
- Use clear labels and placeholders.
- Use required and optional indicators.
- Do not use default 0 values unless truly required.

Use placeholders instead:

- Enter amount
- Enter budget
- Enter stock

Modal button rules:

- Align Cancel and Save buttons at the bottom-right.
- Primary save button uses dark green.
- Cancel button uses neutral style.
- Close button should be consistent.

## Role and Access Control

The system has Admin and Staff roles.

Admin access:

- Full dashboard
- POS
- Sales Records
- Cash Flow
- Inventory
- Fishpond Operations
- Rental Operations
- Proposal Requests
- Approval Requests
- Logbook
- Reports
- Landing Page Content
- Users
- Security and Audit Logs
- Backup

Staff access:

- Dashboard with daily operation summary
- POS
- Own sales records
- Cash flow request submission
- Inventory view
- Stock update request submission
- Fishpond monitoring entry
- Fishpond harvest or expense request
- Rental payment or release request
- Proposal submission
- Logbook entries
- View-only daily reports

Staff should not:

- Delete records
- Edit approved financial records
- Void sales directly
- Create backups
- Manage users
- Access security logs
- Edit landing page content
- Approve proposals
- Change prices directly
- Adjust stock directly
- Edit cash flow directly

## Approval Workflow

- Add an Approval Requests page for Admin.
- Staff sensitive actions should create pending requests first.
- Admin must approve before records become final.

Statuses:

- Pending
- Approved
- Rejected
- Needs Revision
- Cancelled

Actions requiring admin approval:

- Void POS sale
- Edit POS sale
- Add manual cash transaction
- Edit cash transaction
- Delete cash transaction
- Add inventory item
- Edit price
- Edit cost
- Adjust stock
- Add fishpond harvest income
- Add fishpond expense
- Add rental payment
- Edit rental account
- Release toga if deposits or fees are involved
- Approve proposal
- Edit landing page content
- Add user
- Suspend user
- Create backup

Actions not needing approval:

- Create normal POS sale
- Add basic logbook entry
- Add fishpond monitoring note without money
- Submit proposal
- View inventory
- Generate view-only daily report

Approval Requests page columns:

- Date Requested
- Requested By
- Module
- Action Type
- Record Summary
- Amount
- Status
- Actions

Admin actions:

- View Details
- Approve
- Reject
- Request Revision

Every approval record must save:

- Requester
- Module
- Action type
- Old value
- New value
- Admin decision
- Admin remarks
- Decision date
- Approved by or rejected by

Staff messages:

- When staff submits a sensitive action, show: Request submitted for admin approval.
- Do not show Saved successfully when admin approval is still pending.

## Security and Audit

Record all important actions in audit logs:

- Login
- Logout
- Failed login
- Create record
- Update record
- Delete record
- Approve request
- Reject request
- Generate report
- Print report
- Create backup
- Download backup

Security rules:

- Keep audit logs separate from session logs.

## Final Result

- The final UI should look professional, clean, structured, high contrast, and aligned with the green-yellow university palette.
- The Dashboard should contain the main cards and charts.
- Other pages should avoid unnecessary cards and charts.
- Work pages should be fast to operate.
- Reports should print through a professional template, not webpage screenshots.
- All records involving people should use searchable selection from a People or Personnel master list.
- All staff-sensitive actions should go through admin approval.
- Preserve existing features and improve layout, readability, consistency, accessibility, and maintainability.
