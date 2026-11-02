import type { NextConfig } from 'next';
import path from 'node:path';
const config: NextConfig = { distDir: process.env.NODE_ENV === 'development' ? '.next-dev' : '.next', reactStrictMode: true, poweredByHeader: false, outputFileTracingRoot: path.resolve(__dirname, '../..') };
export default config;
