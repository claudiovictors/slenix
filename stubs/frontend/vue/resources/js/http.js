const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content

export async function api(url, { method = 'GET', body, headers = {} } = {}) {
  const res = await fetch(url, {
    method,
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      ...(body !== undefined && { 'Content-Type': 'application/json' }),
      ...(method !== 'GET' && { 'X-CSRF-TOKEN': csrf() }),
      ...headers,
    },
    body: body !== undefined ? JSON.stringify(body) : undefined,
  })

  if (!res.ok) {
    throw Object.assign(new Error(res.statusText), { status: res.status })
  }
  return res.json()
}