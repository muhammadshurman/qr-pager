'use client'

import { useRouter } from 'next/navigation'

import { StaffApp, type StaffView } from '../StaffApp.tsx'

/**
 * The one place the staff shell touches Next's router.
 *
 * `StaffApp` takes an `onNavigate` callback and knows nothing about routing, so every test
 * of it -- unit, jsdom and browser -- drives real navigation or none without a router mock.
 * This component is the adapter, and it is three lines so that it needs no test of its own
 * beyond the browser case that clicks through.
 */
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
        router.push(next === 'devices' ? '/staff/devices' : '/staff')
      }}
    />
  )
}
