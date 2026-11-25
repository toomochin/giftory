const maxPhotoBytes = 2 * 1024 * 1024
const supportedTypes = ['image/jpeg', 'image/png']

export async function prepareGiftPhoto(file) {
  if (!supportedTypes.includes(file.type)) {
    throw { messages: ['JPEGまたはPNGの写真を選んでください。'] }
  }
  if (file.size <= maxPhotoBytes) return file

  const url = URL.createObjectURL(file)
  const canvas = document.createElement('canvas')
  try {
    const image = new Image()
    image.src = url
    await image.decode()
    const context = canvas.getContext('2d')
    if (!context) throw new Error('Canvas unavailable')

    let scale = Math.min(1, 1600 / Math.max(image.naturalWidth, image.naturalHeight))
    for (const quality of [0.86, 0.78, 0.7, 0.65, 0.6]) {
      canvas.width = Math.max(1, Math.round(image.naturalWidth * scale))
      canvas.height = Math.max(1, Math.round(image.naturalHeight * scale))
      context.drawImage(image, 0, 0, canvas.width, canvas.height)
      const blob = await new Promise(resolve => canvas.toBlob(resolve, file.type, quality))
      if (blob && blob.type === file.type && blob.size <= maxPhotoBytes) {
        return new File([blob], file.name, { type: file.type })
      }
      scale *= 0.75
    }
    throw new Error('Image exceeds upload limit')
  } catch {
    throw { messages: ['写真を縮小できませんでした。別のJPEGまたはPNGを選んでください。'] }
  } finally {
    URL.revokeObjectURL(url)
    canvas.width = canvas.height = 0
  }
}
