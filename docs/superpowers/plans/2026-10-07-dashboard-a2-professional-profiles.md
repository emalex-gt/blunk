# Dashboard A2 implementation plan

> Execute from `64951d3`, using TDD. No migration or dependency change.

1. **Write RED profile-resolution tests** in `DashboardFoundationTest`.
   Construct contexts for standard roles and direct/custom capabilities. Assert
   route priority, warehouse and executive selection, owner fallback, and that
   a role preference without its capability set cannot select that profile.

2. **Write RED metric tests** with tenant and active-branch fixtures. Assert
   CxC uses `customer_credit_accounts`; reservations use only `active`; route
   counters use exactly the existing domain states; confirmed settlement value
   excludes reversed, draft, and cancelled rows. Assert no cross-tenant or
   inactive-branch leakage.

3. **Implement the composition layer.** Add a small profile-definition value
   object or metadata API and `DashboardProfileResolver`. Keep profile selection
   separate from `DashboardWidgetDefinition::isVisibleTo()`. Change
   `DashboardService` to resolve the profile when one is not explicitly passed,
   preserve explicit-profile testability, and send profile metadata.

4. **Implement only missing reusable metric queries.** Add CxC, active
   reservations, and route-work metric providers. Each receives
   `DashboardContext` and applies its branch scope consistently. Register them
   with narrow modules and permissions in `DashboardWidgetRegistry`; reuse A1
   sales/stock/margin/top-product providers in profiles.

5. **Update the single dashboard page and write RED/green markup assertions.**
   Render profile title/description and generic count/money values. Do not add
   navigation, selector, or stored UI state. Ensure hidden widgets stay absent.

6. **Verify sequentially.** Run the focused dashboard test filter, then the
   dashboard-related financial visibility test if separate, then `npm run
   build`, `git diff --check`, and repository/stash checks. Do not run a full
   suite unless a focused failure establishes a need.

7. **Review scope before local commit.** Confirm no migrations/lockfiles or
   unrelated domains changed, that standard names are only preferences, and
   that the route settlement query filters only `confirmed` rows. If green,
   create the one local A2 commit: `add professional dashboard profiles`.
