export type Gender = 'boy' | 'girl'

export type GenerateResult = {
  id: string
  image_url: string
  share_url: string
  duration_ms: number
}

export async function generatePortrait(photo: Blob, gender: Gender): Promise<GenerateResult> {
  const body = new FormData()
  body.append('photo', photo, 'selfie.jpg')
  body.append('gender', gender)
  body.append('consent', 'true')

  const response = await fetch('/api/generate', { method: 'POST', body })
  const payload = (await response.json().catch(() => ({}))) as { message?: string }

  if (!response.ok) {
    throw new Error(payload.message || 'We could not create your image. Please try again.')
  }

  return payload as GenerateResult
}
