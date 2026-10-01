FROM node:24.15-alpine AS build
RUN corepack enable && corepack prepare pnpm@10.17.1 --activate
WORKDIR /app
COPY frontend/package.json frontend/pnpm-lock.yaml ./
RUN pnpm install --frozen-lockfile
COPY frontend .
RUN pnpm build

FROM nginx:1.29-alpine
COPY --from=build /app/dist/frontend/browser /usr/share/nginx/html
COPY docker/frontend/default.conf /etc/nginx/conf.d/default.conf
EXPOSE 80
