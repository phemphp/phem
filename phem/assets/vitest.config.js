import { defineConfig } from "vitest/config"

// The tests of the phem controller run in jsdom, with the real Stimulus and Turbo. Turbo renders a
// stream after the next animation frame: `pretendToBeVisual` gives jsdom one.
export default defineConfig({
  test: {
    environment: "jsdom",
    environmentOptions: { jsdom: { pretendToBeVisual: true } },
    include: ["tests/**/*.test.js"],
  },
})
