<template>
	<div>
		<ap-message :data="message"></ap-message>
	</div>
</template>

<script>
module.exports = {
	data() {
		return {
			message: {
				header: apretaste.request.header,
				icon: apretaste.request.icon,
				text: apretaste.request.text
			}
		}
	}
}
</script>

<style scoped>
</style>
