import type { CapacitorConfig } from '@capacitor/cli';

const config: CapacitorConfig = {
  appId: 'talam.app',
  appName: 'طلام',
  webDir: 'dist',
  server: {
    androidScheme: 'https',
  },
};

export default config;
