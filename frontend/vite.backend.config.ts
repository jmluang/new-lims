import { mergeConfig, defineConfig } from 'vite'
import baseConfig from './vite.config'

export default mergeConfig(
  baseConfig,
  defineConfig({
    base: '/app/',
    build: {
      outDir: '../backend/public/app',
      emptyOutDir: true,
    },
  }),
)
