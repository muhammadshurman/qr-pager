'use client'

import { useRouter } from 'next/navigation'

import { StaffApp, type StaffView } from '../StaffApp.tsx'

/**
 * The one place the staff shell touches Next's router.
 *
 * `StaffApp` takes an `onNavigate` callback and knows nothing about routing, so every test
 * of it -- unit, jsdom and browser -- drives real navigation or none without a router mock.
 * This component is the adapter.
 *
 * ⚠ **THE PATH MAP IS TOTAL OVER `StaffView` AND WAS A TERNARY UNTIL 10e.** It read
 * `next === 'devices' ? '/staff/devices' : '/staff'`, which was correct for two views and
 * silently wrong for five: every new view would have navigated to `/staff` and rendered the
 * board, so a person pressing *Past orders* would land on the board with no error anywhere.
 *
 * A `Record<StaffView, string>` makes that a TYPE ERROR instead: a view added to the union
 * without a path here does not compile. `apps/web/test/staff-navigator.test.tsx` drives every
 * entry, and `staff-build-surface.test.ts` asserts each path has a prerendered document — so
 * a path typed here that no route serves fails too.
 */
const PATHS: Readonly<Record<StaffView, string>> = {
  home: '/staff',
  history: '/staff/history',
  issue: '/staff/issue',
  team: '/staff/team',
  devices: '/staff/devices',
}

export function StaffNavigator({
  apiBase,
  view,
}: {
  readonly apiBase: string
  readonly view: StaffView
}) {
  const router = useRouter()

  return (
    <StaffApp
      apiBase={apiBase}
      view={view}
      onNavigate={(next) => {
        router.push(PATHS[next])
      }}
    />
  )
}
