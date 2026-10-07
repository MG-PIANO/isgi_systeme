import { createClient } from '@supabase/supabase-js'

export const supabase = createClient(
  'https://vbdhmgrysrerlmgumafx.supabase.co',
  'sb_publishable_sqJUSK-p5mF2Acy_bhxhAQ_nt_t6Fax',
)

export async function portalRequest<T>(
  action: string,
  payload: Record<string, unknown> = {},
): Promise<T> {
  const { data, error } = await supabase.functions.invoke('portal-api', {
    body: { action, ...payload },
  })
  if (error) {
    const response = error.context
    if (response instanceof Response) {
      let payloadError: string | undefined
      try {
        const payload = await response.clone().json() as { error?: unknown }
        if (typeof payload.error === 'string') payloadError = payload.error
      } catch {
        payloadError = undefined
      }
      if (payloadError) throw new Error(payloadError)
    }
    throw new Error(error.message)
  }
  if (data?.error) throw new Error(String(data.error))
  return data as T
}
